<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SystemSetting;
use App\Models\WhatsAppTemplateCache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    private string $apiUrl;
    private string $phoneNumberId;
    private string $accessToken;
    private string $wabaId;
    private string $businessAccountId;

    public function __construct()
    {
        $this->apiUrl = SystemSetting::getValue('whatsapp_api_url', config('whatsapp.api_url', 'https://graph.facebook.com/v21.0'));
        $this->phoneNumberId = SystemSetting::getValue('whatsapp_phone_number_id', config('whatsapp.phone_number_id', ''));
        $this->accessToken = SystemSetting::getValue('whatsapp_access_token', config('whatsapp.access_token', ''));
        $this->wabaId = SystemSetting::getValue('whatsapp_waba_id', '');
        $this->businessAccountId = SystemSetting::getValue('whatsapp_business_account_id', config('whatsapp.business_account_id', ''));
    }

    public function isConfigured(): bool
    {
        return $this->apiUrl !== ''
            && $this->phoneNumberId !== ''
            && $this->accessToken !== '';
    }

    /**
     * The Meta-issued `wamid.XXX` from the most recent successful send.
     * NotificationService reads this via lastMessageId() to persist into
     * temple_notification_logs.provider_message_id, which the inbound
     * WhatsAppWebhookController later uses to correlate delivery events.
     */
    private ?string $lastMessageId = null;

    public function lastMessageId(): ?string
    {
        return $this->lastMessageId;
    }

    public function sendTemplateMessage(string $phone, string $templateName, string $languageCode, array $components = []): bool
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $this->formatPhone($phone),
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $languageCode],
            ],
        ];

        if (!empty($components)) {
            $payload['template']['components'] = $components;
        }

        return $this->send($payload);
    }

    public function sendTextMessage(string $phone, string $text): bool
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $this->formatPhone($phone),
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $text,
            ],
        ];

        return $this->send($payload);
    }

    public function sendDocument(string $phone, string $documentUrl, string $filename, string $caption = ''): bool
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $this->formatPhone($phone),
            'type' => 'document',
            'document' => [
                'link' => $documentUrl,
                'filename' => $filename,
            ],
        ];

        if ($caption) {
            $payload['document']['caption'] = $caption;
        }

        return $this->send($payload);
    }

    private function send(array $payload): bool
    {
        // Reset on every call — a previous send's message_id must never
        // leak into a subsequent failed send's audit log.
        $this->lastMessageId = null;

        if (empty($this->phoneNumberId) || empty($this->accessToken)) {
            Log::warning('WhatsApp: credentials not configured, skipping message', [
                'to' => $payload['to'] ?? 'unknown',
                'type' => $payload['type'] ?? 'unknown',
            ]);
            return false;
        }

        $url = "{$this->apiUrl}/{$this->phoneNumberId}/messages";

        // Retry layer absorbs transient Meta API failures (rate limits,
        // 5xx blips, network glitches between Hostinger and graph.fb).
        // Previously a single failure silently dropped the message —
        // the "sometimes OTP comes, sometimes not" pattern the admin
        // hit. Timeout dropped from 30s to 10s so three attempts plus
        // backoff still fit inside PHP-FPM's 60s execution budget.
        $maxAttempts = 3;
        $lastError = null;
        $lastResponseError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = Http::withToken($this->accessToken)
                    ->timeout(10)
                    ->post($url, $payload);

                if ($response->successful()) {
                    // Cache the message id so the calling driver can stash
                    // it on temple_notification_logs.provider_message_id.
                    // Webhook delivery events later JOIN back on this id.
                    //
                    // Try Meta's standard shape first ({messages: [{id}]}),
                    // then common BSP wrappers. The Internet Store BSP
                    // proxies our send calls and re-wraps Meta's response —
                    // their exact envelope is logged in 'WhatsApp send raw
                    // response' below the first time it differs.
                    $this->lastMessageId = $response->json('messages.0.id')           // Meta direct
                        ?? $response->json('data.messages.0.id')                       // common BSP wrap #1
                        ?? $response->json('result.messages.0.id')                     // common BSP wrap #2
                        ?? $response->json('data.message_id')                          // flat BSP shape
                        ?? $response->json('message_id')                               // flat BSP shape root
                        ?? $response->json('id')                                        // ultra-flat
                        ?? null;

                    if ($this->lastMessageId === null) {
                        // First-time recon: dump the full JSON so we can
                        // see the BSP's exact response shape and extend
                        // the lookup chain above. Truncated to 4KB to
                        // keep the log readable.
                        Log::warning('WhatsApp send: response 2xx but message_id not found in any known shape', [
                            'to' => $payload['to'],
                            'response_body' => substr($response->body(), 0, 4096),
                        ]);
                    }

                    Log::info('WhatsApp message sent', [
                        'to' => $payload['to'],
                        'type' => $payload['type'],
                        'attempt' => $attempt,
                        'message_id' => $this->lastMessageId,
                    ]);
                    return true;
                }

                $lastResponseError = $response->json('error');
                Log::warning('WhatsApp API non-2xx', [
                    'to' => $payload['to'],
                    'attempt' => $attempt,
                    'status' => $response->status(),
                    'error' => $lastResponseError,
                ]);

                // 4xx auth / validation errors won't change on retry —
                // bail immediately so we don't waste the budget.
                if ($response->status() >= 400 && $response->status() < 500
                    && ! in_array($response->status(), [408, 429], true)) {
                    break;
                }
            } catch (\Throwable $e) {
                $lastError = $e;
                Log::warning('WhatsApp send attempt failed', [
                    'to' => $payload['to'],
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($attempt < $maxAttempts) {
                usleep($attempt * 1_000_000); // 1s, 2s
            }
        }

        Log::error('WhatsApp send permanently failed', [
            'to' => $payload['to'],
            'attempts' => $maxAttempts,
            'last_error' => $lastError?->getMessage(),
            'last_response_error' => $lastResponseError,
        ]);
        return false;
    }

    /**
     * Verify the configured credentials by fetching the phone-number's
     * own profile from Meta. The endpoint is cheap and only succeeds
     * when api_url + phone_number_id + access_token are all valid.
     *
     * @return array{ok: bool, message: string, details?: array}
     */
    public function testConnection(): array
    {
        if (! $this->isConfigured()) {
            return [
                'ok' => false,
                'message' => 'API URL, Phone Number ID and Access Token are required.',
            ];
        }

        try {
            $response = Http::withToken($this->accessToken)
                ->timeout(15)
                ->get("{$this->apiUrl}/{$this->phoneNumberId}", [
                    'fields' => 'display_phone_number,verified_name,quality_rating',
                ]);

            if ($response->successful()) {
                return [
                    'ok' => true,
                    'message' => 'Connected as ' . ($response->json('verified_name') ?: 'unknown')
                        . ' (' . ($response->json('display_phone_number') ?: 'no phone') . ').',
                    'details' => $response->json(),
                ];
            }

            $err = $response->json('error');
            return [
                'ok' => false,
                'message' => $err['message'] ?? "WhatsApp API returned HTTP {$response->status()}.",
                'details' => $err ?? ['status' => $response->status()],
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => 'Could not reach the WhatsApp API: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Pull approved message templates from the WABA and refresh the
     * local cache table so the admin UI's template-name dropdown is
     * populated. Requires `whatsapp_waba_id` to be set.
     *
     * @return array{ok: bool, message: string, count?: int, error?: array}
     */
    public function fetchTemplates(): array
    {
        if (! $this->isConfigured()) {
            return [
                'ok' => false,
                'message' => 'WhatsApp API not configured.',
            ];
        }
        if ($this->wabaId === '') {
            return [
                'ok' => false,
                'message' => 'WABA ID is required to list templates.',
            ];
        }

        try {
            // Marker used to identify rows not seen in *this* sync —
            // anything still older than this after the upsert loop has
            // either been withdrawn at Meta or is no longer APPROVED,
            // so we drop it from the cache.
            $syncMarker = now()->subSecond();

            $response = Http::withToken($this->accessToken)
                ->timeout(30)
                ->get("{$this->apiUrl}/{$this->wabaId}/message_templates", [
                    'limit' => 100,
                    'fields' => 'name,language,category,status,components,id',
                ]);

            if (! $response->successful()) {
                $err = $response->json('error');
                return [
                    'ok' => false,
                    'message' => $err['message'] ?? "Templates fetch failed with HTTP {$response->status()}.",
                    'error' => $err ?? ['status' => $response->status()],
                ];
            }

            $rows = $response->json('data') ?? [];
            $count = 0;
            foreach ($rows as $row) {
                if (! is_array($row) || empty($row['name']) || empty($row['language'])) continue;
                if (($row['status'] ?? null) !== 'APPROVED') continue;

                WhatsAppTemplateCache::updateOrCreate(
                    ['name' => $row['name'], 'language' => $row['language']],
                    [
                        'category' => $row['category'] ?? null,
                        'status' => $row['status'],
                        'components' => $row['components'] ?? null,
                        'meta_template_id' => $row['id'] ?? null,
                        'synced_at' => now(),
                    ]
                );
                $count++;
            }

            WhatsAppTemplateCache::where('synced_at', '<', $syncMarker)
                ->orWhereNull('synced_at')
                ->delete();

            return [
                'ok' => true,
                'message' => "Synced {$count} approved template" . ($count === 1 ? '' : 's') . '.',
                'count' => $count,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => 'Could not reach the WhatsApp API: ' . $e->getMessage(),
            ];
        }
    }

    private function formatPhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);

        if (strlen($phone) === 10) {
            return '91' . $phone;
        }

        if (str_starts_with($phone, '91') && strlen($phone) === 12) {
            return $phone;
        }

        return '91' . $phone;
    }
}
