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

    /**
     * MSG91's submission id from the most recent successful send.
     *
     * NotificationService::deliver() reads this through the driver's
     * lastMessageId() and persists it onto
     * temple_notification_logs.provider_message_id — which is the ONLY
     * key the inbound delivery report can be matched back on. Without it
     * a delivery report is an orphan: we would know some message failed
     * but not which devotee, trigger or template it belonged to.
     */
    private ?string $lastMessageId = null;

    /**
     * SystemSetting key holding the random token embedded in the webhook
     * URL. See webhookToken() for what this does and does not protect.
     */
    public const WEBHOOK_TOKEN_KEY = 'sms_msg91_webhook_token';

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
     * MSG91's request id for the last successful send, or null.
     *
     * Mirrors WhatsAppService::lastMessageId() so
     * NotificationService::deliver()'s method_exists() probe picks it up
     * with no change to the driver contract.
     */
    public function lastMessageId(): ?string
    {
        return $this->lastMessageId;
    }

    // ─────────────────────────────────────────────────────────────────
    //  Delivery-report webhook: token + URL
    // ─────────────────────────────────────────────────────────────────

    /**
     * The random token embedded in the delivery-report webhook URL.
     *
     * WHAT THIS IS: MSG91's delivery-report ("DLR" / callback) setting is
     * a bare URL field. It does not offer a signing secret, an HMAC, or a
     * custom request header — MSG91 POSTs the report and nothing else. A
     * capability URL is therefore the only mechanism available: the secret
     * IS the URL.
     *
     * WHAT THIS IS NOT: this is not authentication of MSG91. Anyone
     * holding the URL can POST to it. What the token buys is (a) the
     * endpoint is not discoverable by scanning /api/webhooks/*, and (b) it
     * can be rotated the instant it leaks. The blast radius if it ever
     * does leak is bounded: a forged report can only alter the delivery
     * status shown on an already-sent message. It cannot send anything,
     * read anything, or touch money.
     *
     * Generated on first read so a fresh install never has an empty token
     * (an empty token is rejected outright by the controller rather than
     * matching an empty URL segment).
     */
    public static function webhookToken(): string
    {
        $token = (string) SystemSetting::getValue(self::WEBHOOK_TOKEN_KEY, '');

        if (strlen($token) < 32) {
            $token = self::regenerateWebhookToken();
        }

        return $token;
    }

    /**
     * Mint a new token. INVALIDATES the URL already pasted into MSG91 —
     * delivery reports stop arriving until the new URL is pasted in.
     */
    public static function regenerateWebhookToken(): string
    {
        // 48 hex chars from a CSPRNG. Long enough that guessing is not a
        // consideration, short enough to stay on one line in the MSG91
        // dashboard's URL field.
        $token = bin2hex(random_bytes(24));

        SystemSetting::updateOrCreate(
            ['key' => self::WEBHOOK_TOKEN_KEY],
            ['value' => $token, 'group' => 'sms', 'updated_at' => now()]
        );

        return $token;
    }

    /** Full, paste-ready delivery-report URL including the token. */
    public static function webhookUrl(?string $token = null): string
    {
        return rtrim((string) config('app.url'), '/')
            . '/api/webhooks/msg91/'
            . ($token ?? self::webhookToken());
    }

    // ─────────────────────────────────────────────────────────────────
    //  OTP template variable names
    // ─────────────────────────────────────────────────────────────────

    /**
     * MSG91 Flow matches recipient keys to the variable NAMES in the DLT
     * template — it is not positional.
     *
     * This is the bug that stopped every real OTP SMS from arriving. The
     * trust's approved DLT template reads:
     *
     *   "Your OTP for logging in to SPHST App/Web Portal is ##OTP##. This
     *    OTP is valid for ##mins## minutes. Do not share this OTP with
     *    anyone. - Shree Patadiya Hanuman Seva Trust"
     *
     * so its variables are named `OTP` and `mins`. The code was sending
     * `var1` / `var2` — MSG91's DLT-positional convention, which this
     * template does not use — so neither placeholder was ever filled and
     * MSG91 rejected the submission ("Template ID Missing or Invalid
     * Template"). That rejection is invisible at send time because the
     * Flow API answers HTTP 200 {"type":"success"} regardless.
     *
     * The names are settings rather than constants because the next DLT
     * template the trust registers will use different ones, and a
     * hardcoded pair would spring exactly the same trap a second time
     * with no clue in the UI as to why.
     */
    public function otpVariableName(): string
    {
        return self::sanitiseVariableName(
            SystemSetting::getValue('sms_msg91_otp_var_name', ''),
            'OTP',
        );
    }

    public function otpValidityVariableName(): string
    {
        return self::sanitiseVariableName(
            SystemSetting::getValue('sms_msg91_otp_validity_var_name', ''),
            'mins',
        );
    }

    /**
     * Build the MSG91 recipient variable map for an OTP send.
     *
     * @param  string    $code    The 6-digit OTP.
     * @param  int|null  $minutes Validity window; defaults to the value
     *                            OtpService actually enforces, so the
     *                            message can never promise a different
     *                            window from the one the server honours.
     * @return array<string, string>
     */
    public function otpVariables(string $code, ?int $minutes = null): array
    {
        return [
            $this->otpVariableName() => $code,
            $this->otpValidityVariableName() => (string) ($minutes ?? \App\Services\OtpService::expiryMinutes()),
        ];
    }

    /**
     * MSG91 variable names are bare identifiers (the text between the
     * ##…## markers). Strip anything that could not appear there —
     * including the ## the admin will inevitably paste along with the
     * name — and fall back to the default when nothing usable is left.
     */
    public static function sanitiseVariableName(?string $name, string $fallback): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_]/', '', (string) $name) ?? '';

        return $clean !== '' ? $clean : $fallback;
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
        // Clear first: a failed send must never leave the PREVIOUS send's
        // request id hanging around for NotificationService to staple onto
        // the wrong log row.
        $this->lastMessageId = null;

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
                'phone' => self::maskPhone($phone),
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
                // MSG91's submission id. This is the ONLY handle we will
                // ever have on this message: the delivery report echoes it
                // back as `requestId`, and matching it against
                // temple_notification_logs.provider_message_id is what
                // turns an anonymous "message 3 failed" into "the OTP we
                // sent this devotee at 14:02 was rejected because …".
                //
                // Key spelling varies by MSG91 account/route, hence the
                // ladder. `message` is the legacy field where older Flow
                // responses put the same id.
                $this->lastMessageId = self::firstScalar([
                    $response->json('request_id'),
                    $response->json('requestId'),
                    $response->json('data.request_id'),
                    $response->json('message'),
                ]);

                Log::info('SMS submitted to MSG91', [
                    'phone' => self::maskPhone($phone),
                    'template_id' => $templateId,
                    'message_id' => $this->lastMessageId,
                    // Deliberate wording: MSG91 has ACCEPTED the request,
                    // which it does even for a wrong auth key. Nothing here
                    // means the devotee received anything.
                    'note' => 'accepted by MSG91; delivery unconfirmed until a delivery report arrives',
                ]);
                return [
                    'ok' => true,
                    'message' => 'Submitted to MSG91. Delivery is confirmed only by the delivery report — MSG91 accepts before it validates.',
                    'request_id' => $this->lastMessageId,
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
                'phone' => self::maskPhone($phone),
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
                'phone' => self::maskPhone($phone),
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

    /**
     * The platform's one masking rule for mobile numbers: 91••••3210.
     *
     * Static + public because the delivery-report webhook and the admin
     * "recent events" panel must mask exactly the same way this service
     * already does — a second, subtly different implementation is how a
     * full number eventually reaches a screen or a log line.
     */
    public static function maskPhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($phone) >= 4) {
            return substr($phone, 0, 2) . '••••' . substr($phone, -4);
        }
        return '••••';
    }

    /**
     * First non-empty scalar in a list, as a string. Used to walk the
     * ladder of key spellings MSG91 uses for the same field across
     * accounts and routes.
     *
     * @param  array<int, mixed>  $candidates
     */
    private static function firstScalar(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
            if (is_int($candidate)) {
                return (string) $candidate;
            }
        }

        return null;
    }
}
