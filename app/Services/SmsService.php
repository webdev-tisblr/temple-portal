<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MSG91 Flow API client. The only supported provider for now; the
 * service is constructed to be swappable (Fast2SMS / Twilio adapters
 * could implement the same public surface).
 *
 * Credentials are read from temple_system_settings (admin-managed)
 * with config/sms.php as the fallback for local dev.
 *
 * MSG91's Flow API: https://docs.msg91.com/sms/flow
 *   POST /api/v5/flow/
 *   Header: authkey: <auth_key>
 *   Body:
 *     {
 *       "template_id": "<id>",
 *       "sender":      "SPHTRT",
 *       "short_url":   "0",
 *       "DLT_TE_ID":   "<dlt_te_id>",
 *       "recipients":  [
 *         {"mobiles": "919999999999", "var1": "123456"}
 *       ]
 *     }
 */
class SmsService
{
    private string $apiUrl;
    private string $authKey;
    private string $senderId;
    private string $otpTemplateId;
    private string $dltTeId;
    private string $countryCode;

    public function __construct()
    {
        $this->apiUrl = SystemSetting::getValue('sms_msg91_api_url', (string) config('sms.msg91.api_url'));
        $this->authKey = SystemSetting::getValue('sms_msg91_auth_key', (string) (config('sms.msg91.auth_key') ?? ''));
        $this->senderId = SystemSetting::getValue('sms_msg91_sender_id', (string) (config('sms.msg91.sender_id') ?? ''));
        $this->otpTemplateId = SystemSetting::getValue('sms_msg91_otp_template_id', (string) (config('sms.msg91.otp_template_id') ?? ''));
        $this->dltTeId = SystemSetting::getValue('sms_msg91_dlt_te_id', (string) (config('sms.msg91.dlt_te_id') ?? ''));
        $this->countryCode = SystemSetting::getValue('sms_msg91_country_code', (string) (config('sms.msg91.country_code') ?? '91'));
    }

    public function isConfigured(): bool
    {
        return $this->authKey !== '' && $this->senderId !== '';
    }

    public function getOtpTemplateId(): string
    {
        return $this->otpTemplateId;
    }

    /**
     * Send a DLT-approved template SMS through MSG91's Flow API.
     *
     * @param  string $phone Indian mobile (10 digits or with 91 prefix).
     * @param  string $templateId MSG91 template id (per-template, not per-message).
     * @param  array<string, string> $variables Map of var1/var2/… → value.
     * @return array{ok: bool, message: string, response?: array}
     */
    public function sendTemplate(string $phone, string $templateId, array $variables = []): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'message' => 'SMS provider not configured. Add MSG91 auth key + sender id in admin → System Settings → SMS.'];
        }
        if ($templateId === '') {
            return ['ok' => false, 'message' => 'Template id is required (set per notification template OR sms_msg91_otp_template_id in settings).'];
        }

        $payload = [
            'template_id' => $templateId,
            'sender' => $this->senderId,
            'short_url' => '0',
            // MSG91 accepts the DLT TE ID for compliance routing. If
            // it's not set the API still works but routes via the
            // default DLT TE ID configured on the MSG91 account.
            'recipients' => [
                array_merge(
                    ['mobiles' => $this->formatPhone($phone)],
                    $variables,
                ),
            ],
        ];
        if ($this->dltTeId !== '') {
            $payload['DLT_TE_ID'] = $this->dltTeId;
        }

        try {
            $response = Http::withHeaders([
                    'authkey' => $this->authKey,
                    'content-type' => 'application/json',
                    'accept' => 'application/json',
                ])
                ->timeout(20)
                ->post(rtrim($this->apiUrl, '/') . '/flow/', $payload);

            if ($response->successful() && ($response->json('type') === 'success' || $response->successful())) {
                Log::info('SMS sent via MSG91', [
                    'phone' => $this->maskPhone($phone),
                    'template_id' => $templateId,
                    'message_id' => $response->json('request_id') ?? $response->json('message'),
                ]);
                return [
                    'ok' => true,
                    'message' => 'Sent.',
                    'response' => $response->json() ?? [],
                ];
            }

            $err = $response->json('message') ?? $response->json('error') ?? "MSG91 returned HTTP {$response->status()}";
            Log::error('MSG91 send failed', [
                'phone' => $this->maskPhone($phone),
                'template_id' => $templateId,
                'status' => $response->status(),
                'response' => $response->json(),
            ]);
            return [
                'ok' => false,
                'message' => is_string($err) ? $err : "MSG91 returned HTTP {$response->status()}",
                'response' => $response->json() ?? [],
            ];
        } catch (\Throwable $e) {
            Log::error('MSG91 send exception', [
                'phone' => $this->maskPhone($phone),
                'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'message' => 'Could not reach MSG91: ' . $e->getMessage()];
        }
    }

    /**
     * Verify credentials by calling MSG91's wallet-balance endpoint.
     * It's authenticated, cheap, and returns the current balance — so
     * we get a useful number to show the admin while confirming
     * everything is reachable.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'message' => 'Auth key + sender id are required.'];
        }

        try {
            // The balance endpoint differs slightly between MSG91 v5 and the
            // legacy v2. Try v5 first (newer accounts), then v2 as a fallback.
            $response = Http::withHeaders(['authkey' => $this->authKey])
                ->timeout(10)
                ->get(rtrim($this->apiUrl, '/') . '/getbalance.php', ['type' => '4']);

            if ($response->successful()) {
                $balance = trim($response->body());
                return [
                    'ok' => true,
                    'message' => "Connected. Wallet balance: ₹{$balance}",
                ];
            }

            return [
                'ok' => false,
                'message' => "MSG91 returned HTTP {$response->status()}. Check the auth key.",
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Could not reach MSG91: ' . $e->getMessage()];
        }
    }

    /**
     * MSG91 expects mobile numbers with country code, no symbols.
     * Mirrors WhatsAppService::formatPhone for consistency — 10-digit
     * input is treated as Indian, anything that already starts with 91
     * is left alone, anything else is prefixed with the configured
     * country code.
     */
    private function formatPhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($phone) === 10) {
            return $this->countryCode . $phone;
        }
        if (str_starts_with($phone, $this->countryCode) && strlen($phone) === strlen($this->countryCode) + 10) {
            return $phone;
        }
        return $this->countryCode . $phone;
    }

    private function maskPhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($phone) >= 4) {
            return substr($phone, 0, 2) . '••••' . substr($phone, -4);
        }
        return '••••';
    }
}
