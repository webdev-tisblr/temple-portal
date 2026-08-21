<?php

declare(strict_types=1);

namespace App\Services\Notifications\Drivers;

use App\Models\NotificationTemplate;
use App\Services\Notifications\Contracts\NotificationDriver;
use App\Services\Notifications\NotificationContext;
use App\Services\Notifications\RecipientResolver;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp channel — sends an approved template (Meta Cloud API) with
 * the parameters resolved from $context via the template's
 * placeholder_map and wa_components blueprint.
 *
 * The wa_components blueprint mirrors Meta's spec but with placeholder
 * tokens instead of literal values:
 *
 *   [
 *     {"type": "header", "parameters": [{"type": "text", "value_token": "donor_name"}]},
 *     {"type": "body",   "parameters": [
 *        {"type": "text", "value_token": "amount"},
 *        {"type": "text", "value_token": "receipt_number"}
 *     ]},
 *     {"type": "button", "sub_type": "url", "index": 0,
 *        "parameters": [{"type": "text", "value_token": "receipt_path"}]}
 *   ]
 *
 * Each `value_token` is run through placeholder_map → context, so the
 * admin maps "donor_name" → "donation.devotee.name" once and re-uses it.
 */
final class WhatsAppNotificationDriver implements NotificationDriver
{
    public function __construct(
        private readonly RecipientResolver $recipients,
        private readonly WhatsAppService $whatsapp,
    ) {
    }

    public function channel(): string
    {
        return NotificationTemplate::CHANNEL_WHATSAPP;
    }

    /**
     * Surface the Meta wamid from the most recent successful send.
     * NotificationService::deliver() calls this via method_exists() to
     * persist provider_message_id onto the notification log row.
     */
    public function lastMessageId(): ?string
    {
        return $this->whatsapp->lastMessageId();
    }

    public function send(NotificationTemplate $template, NotificationContext $context): bool
    {
        $variant = $template->waVariantFor($this->resolveLocale($context));

        if ($variant === null || empty($variant['template_name'])) {
            Log::warning('Notification: WhatsApp template name missing', [
                'template_key' => $template->key,
            ]);
            return false;
        }

        $recipient = $this->recipients->resolve($template, $context, 'phone');
        if ($recipient === null) {
            Log::warning('Notification: WhatsApp recipient unresolved', [
                'template_key' => $template->key,
                'recipient_strategy' => $template->recipient_strategy,
            ]);
            return false;
        }

        $components = self::buildComponents(
            $variant['components'] ?? [],
            $template->placeholder_map ?? [],
            $context,
        );

        try {
            return $this->whatsapp->sendTemplateMessage(
                $recipient['value'],
                $variant['template_name'],
                $variant['language_code'] ?? 'en',
                $components,
            );
        } catch (\Throwable $e) {
            Log::error('Notification: WhatsApp send failed', [
                'template_key' => $template->key,
                'to' => $recipient['value'],
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Devotee locale for variant selection: context 'locale' override →
     * devotee.language → gu. Mirrors PushNotificationDriver.
     */
    private function resolveLocale(NotificationContext $context): string
    {
        return $context->locale();
    }

    /**
     * Convert the admin-friendly blueprint into the literal `components`
     * array that Meta's Cloud API expects. Tokens are looked up against
     * the template's placeholder_map first, then against $context as a
     * raw dot-path.
     *
     * Static and public so the parameter rules — in particular the
     * "never hand Meta an empty string" guard below — can be asserted
     * directly, without standing up an HTTP double for the BSP.
     */
    public static function buildComponents(
        array $blueprint,
        array $placeholderMap,
        NotificationContext $context,
    ): array {
        $out = [];
        foreach ($blueprint as $component) {
            if (! is_array($component) || empty($component['type'])) continue;

            $entry = ['type' => $component['type']];
            if (! empty($component['sub_type'])) $entry['sub_type'] = $component['sub_type'];
            if (isset($component['index'])) $entry['index'] = (int) $component['index'];

            $params = [];
            foreach (($component['parameters'] ?? []) as $p) {
                if (! is_array($p)) continue;
                $type = $p['type'] ?? 'text';

                if ($type === 'text') {
                    // Three input shapes the form layer can emit:
                    //   value_token = "donor_name"           → resolve via map
                    //   value       = "{{ donor_name }} hi"  → render mixed string
                    //   value       = "Hello"                → literal
                    // Either path goes through NotificationContext's
                    // display formatter so Carbon date casts render as
                    // "15 May 2026" instead of Carbon's default
                    // "Y-m-d H:i:s" (the previous (string) cast leaked
                    // " 00:00:00" into WhatsApp messages — see
                    // 2026-05-13 commit).
                    $token = $p['value_token'] ?? null;
                    $literal = $p['value'] ?? null;
                    $mappedPath = $token !== null ? ($placeholderMap[$token] ?? $token) : null;

                    if ($token !== null) {
                        // Warn when admin forgot to map a token. Meta's API
                        // will reject template messages with empty params,
                        // so a silent empty here used to manifest as cryptic
                        // "Invalid parameter value" errors hours later.
                        if (! array_key_exists($token, $placeholderMap)) {
                            Log::warning('Notification: WhatsApp value_token has no placeholder_map entry — falling back to dot-path', [
                                'token' => $token,
                                'fallback_path' => $token,
                            ]);
                        }
                        $resolved = $context->getForDisplay($mappedPath, '');
                    } else {
                        $resolved = $context->render((string) ($literal ?? ''), $placeholderMap);
                    }

                    // Meta rejects a body parameter containing a newline, a
                    // tab, or 4+ consecutive spaces — the whole send fails with
                    // (#132000)/(#131008), which is the family of errors that
                    // cost an evening in 2026-05-14. Nothing upstream stopped a
                    // multi-line value reaching here, so it is flattened at the
                    // boundary: a list placeholder degrades to one readable
                    // line instead of killing the message.
                    $text = self::flattenForWhatsApp($resolved);

                    // LAST LINE OF DEFENCE (2026-08-21). Meta rejects the
                    // WHOLE message if any template parameter is an empty
                    // string — not the parameter, the message. Until now an
                    // empty value was logged and sent anyway, so a devotee
                    // whose name was blank (signup creates the row with
                    // name => '') received no booking confirmation, no
                    // receipt and no greeting card at all, and the only
                    // trace was a warning in the log.
                    //
                    // The blank name itself is now prevented at every
                    // entry point; this keeps ANY future blank — a
                    // mis-mapped token, a null column, a context key a
                    // dispatch site forgot — from silently costing the
                    // devotee the entire message.
                    if ($text === '') {
                        $text = self::blankParamFallback($token, $context->locale());
                        Log::warning('Notification: WhatsApp text parameter resolved to empty string — substituted fallback', [
                            'token' => $token,
                            'mapped_path' => $mappedPath,
                            'fallback' => $text,
                        ]);
                    }

                    $params[] = ['type' => 'text', 'text' => $text];
                } elseif (in_array($type, ['image', 'document', 'video'], true)) {
                    // Media param — URLs / filenames are always strings,
                    // so no date-formatting concerns here, but they go
                    // through the same display path for consistency.
                    $token = $p['value_token'] ?? null;
                    $literal = $p['value'] ?? ($p['link'] ?? '');
                    $url = $token !== null
                        ? $context->getForDisplay($placeholderMap[$token] ?? $token, '')
                        : $context->render((string) $literal, $placeholderMap);
                    $media = ['link' => $url];
                    if (! empty($p['filename'])) {
                        $media['filename'] = $context->render((string) $p['filename'], $placeholderMap);
                    }
                    $params[] = ['type' => $type, $type => $media];
                } else {
                    // Currencies / dates / etc — pass through verbatim.
                    $params[] = $p;
                }
            }
            if (! empty($params)) $entry['parameters'] = $params;

            $out[] = $entry;
        }
        return $out;
    }

    /**
     * Tokens that name a PERSON. A blank one reads badly as a dash, so
     * these get the respectful generic word instead — "Dear devotee"
     * rather than "Dear -". Matched exactly: `seva_name` and
     * `campaign_name` also contain "name" and must NOT be caught.
     */
    private const PERSON_NAME_TOKENS = [
        'name', 'devotee_name', 'donor_name', 'contact_name',
        'customer_name', 'guest_name', 'booked_by', 'recipient_name',
    ];

    /** Generic stand-in for a missing person name, per recipient locale. */
    private const PERSON_NAME_FALLBACK = [
        'gu' => 'ભક્ત',
        'hi' => 'भक्त',
        'en' => 'Devotee',
    ];

    /**
     * What to send in place of a parameter that resolved to nothing.
     * Never returns an empty string — that is the whole point.
     */
    private static function blankParamFallback(?string $token, string $locale): string
    {
        if ($token !== null && in_array($token, self::PERSON_NAME_TOKENS, true)) {
            return self::PERSON_NAME_FALLBACK[$locale] ?? self::PERSON_NAME_FALLBACK['gu'];
        }

        // Everything else: a single dash. Visible enough that a devotee
        // can tell something is missing, harmless enough that the rest
        // of the message still gets through.
        return '-';
    }

    /**
     * Make any resolved value safe to hand Meta as a template parameter.
     *
     * Newlines and tabs become ", " so a multi-line list still reads as a
     * list; runs of whitespace collapse because 4+ consecutive spaces are
     * rejected too. Deliberately applied to EVERY text parameter, not just
     * the list ones — the constraint is Meta's, so the guard belongs at the
     * boundary rather than in each caller that might forget.
     */
    public static function flattenForWhatsApp(?string $value): string
    {
        $value = (string) $value;

        // Newline/tab → separator, but don't create ", , " out of blank lines.
        $value = preg_replace('/[\r\n\t]+/u', ', ', $value) ?? $value;
        $value = preg_replace('/(,\s*){2,}/u', ', ', $value) ?? $value;
        $value = preg_replace('/[ ]{2,}/u', ' ', $value) ?? $value;

        return trim($value, " ,\t\n\r");
    }
}
