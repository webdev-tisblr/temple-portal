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
        // MSG91 DLT routes are India-only. Non-Indian numbers must go via
        // WhatsApp/email (OtpService already excludes the sms channel for
        // them); fail honestly if one ever reaches this driver.
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        $isIndian = \App\Support\PhoneNumber::isIndian($digits)
            || (strlen($digits) === 12 && preg_match('/^91[6-9]\d{9}$/', $digits));
        if (! $isIndian) {
            Log::warning('SMS skipped: non-Indian number (MSG91 is India-only)', [
                'phone' => $this->maskPhone($phone),
                'template_id' => $templateId,
            ]);
            return ['ok' => false, 'message' => 'SMS not sent: MSG91 delivers to Indian numbers only.'];
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
                ->post($this->flowEndpoint(), $payload);

            // MSG91 answers HTTP 200 for LOGICAL failures too, with
            // {"type":"error","message":"Template ID Missing or Invalid
            // Template"} in the body. The old condition was
            // `successful() && (type === 'success' || successful())`, whose
            // right-hand side is always true when the left is — so it
            // collapsed to a bare status check and reported "Sent." for
            // every rejected message. That is why the admin saw "Test OTP
            // sent" while MSG91's dashboard logged an invalid template.
            if ($response->successful() && $response->json('type') !== 'error') {
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

            // MSG91's own wording is far more useful than ours ("Template ID
            // Missing or Invalid Template" tells the admin exactly what to
            // fix), so pass it through verbatim and say which template and
            // endpoint produced it.
            if (is_string($err) && $err !== '') {
                $err = "MSG91: {$err} (template {$templateId})";
            }

            Log::error('MSG91 send failed', [
                'phone' => $this->maskPhone($phone),
                'template_id' => $templateId,
                'endpoint' => $this->flowEndpoint(),
                'sender' => $this->senderId,
                'variables' => array_keys($variables),
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
            // What this CAN prove: the endpoint is reachable and correctly
            // formed. What it CANNOT prove: that the auth key, sender id or
            // template are valid.
            //
            // Measured against the live account on 2026-08-10: MSG91's Flow
            // API answers {"type":"success"} to a POST carrying a
            // deliberately wrong auth key, and the legacy balance.php
            // returns "0" for a wrong key just as readily as a right one.
            // Neither validates anything synchronously — rejections surface
            // afterwards in the MSG91 dashboard. Claiming "Connected, key
            // OK" from either would be a guess dressed as a check, which is
            // how the previous version came to report a wallet balance it
            // had not actually read.
            //
            // Empty recipients: nothing can be delivered by this probe.
            $response = Http::withHeaders([
                    'authkey' => $this->authKey,
                    'content-type' => 'application/json',
                    'accept' => 'application/json',
                ])
                ->timeout(10)
                ->post($this->flowEndpoint(), [
                    'template_id' => $this->otpTemplateId !== '' ? $this->otpTemplateId : 'connectivity-probe',
                    'recipients' => [],
                ]);

            if (! $response->successful()) {
                return [
                    'ok' => false,
                    'message' => "MSG91 returned HTTP {$response->status()} for {$this->flowEndpoint()}.",
                ];
            }

            return [
                'ok' => true,
                'message' => 'Endpoint reachable ('.$this->flowEndpoint().'). '
                    .'Note: MSG91 accepts requests before validating the auth key, sender id or template — '
                    .'those failures appear in the MSG91 dashboard, not here. '
                    .'Use "Send test OTP" to a real number and check delivery.',
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Could not reach MSG91: ' . $e->getMessage()];
        }
    }

    /**
     * The Flow send endpoint, however the admin typed the base URL.
     *
     * The setting has been seen as the bare host, as `.../api/v5`, and as
     * `.../api/v5/flow` — the last of which used to produce
     * `.../api/v5/flow/flow/`. Normalise instead of trusting the input:
     * strip any trailing `flow` segment, then add exactly one back.
     */
    public function flowEndpoint(): string
    {
        $base = rtrim(trim($this->apiUrl), '/');
        $base = preg_replace('#/flow$#i', '', $base) ?? $base;

        if ($base === '' || ! preg_match('#^https?://#i', $base)) {
            $base = 'https://control.msg91.com/api/v5';
        }

        return $base . '/flow/';
    }

    /** Legacy balance endpoint — always at the API root of the same host. */
    public function balanceEndpoint(): string
    {
        $host = parse_url($this->flowEndpoint(), PHP_URL_HOST) ?: 'control.msg91.com';

        return 'https://' . $host . '/api/balance.php';
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
