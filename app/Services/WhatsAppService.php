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
        if (empty($this->phoneNumberId) || empty($this->accessToken)) {
            Log::warning('WhatsApp: credentials not configured, skipping message', [
                'to' => $payload['to'] ?? 'unknown',
                'type' => $payload['type'] ?? 'unknown',
            ]);
            return false;
        }

        $url = "{$this->apiUrl}/{$this->phoneNumberId}/messages";

        try {
            $response = Http::withToken($this->accessToken)
                ->timeout(30)
                ->post($url, $payload);

            if ($response->successful()) {
                Log::info('WhatsApp message sent', [
                    'to' => $payload['to'],
                    'type' => $payload['type'],
                    'message_id' => $response->json('messages.0.id'),
                ]);
                return true;
            }

            Log::error('WhatsApp API error', [
                'to' => $payload['to'],
                'status' => $response->status(),
                'error' => $response->json('error'),
            ]);
            return false;

        } catch (\Exception $e) {
            Log::error('WhatsApp send failed', [
                'to' => $payload['to'],
                'error' => $e->getMessage(),
            ]);
            return false;
        }
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
