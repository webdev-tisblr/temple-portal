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

    public function send(NotificationTemplate $template, NotificationContext $context): bool
    {
        if (empty($template->wa_template_name)) {
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

        $components = $this->buildComponents(
            $template->wa_components ?? [],
            $template->placeholder_map ?? [],
            $context,
        );

        try {
            return $this->whatsapp->sendTemplateMessage(
                $recipient['value'],
                $template->wa_template_name,
                $template->wa_template_language ?? 'en',
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
     * Convert the admin-friendly blueprint into the literal `components`
     * array that Meta's Cloud API expects. Tokens are looked up against
     * the template's placeholder_map first, then against $context as a
     * raw dot-path.
     */
    private function buildComponents(
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
                        $resolved = $context->getForDisplay($placeholderMap[$token] ?? $token, '');
                        if ($resolved === '') {
                            Log::warning('Notification: WhatsApp text parameter resolved to empty string', [
                                'token' => $token,
                                'mapped_path' => $placeholderMap[$token] ?? $token,
                            ]);
                        }
                    } else {
                        $resolved = $context->render((string) ($literal ?? ''), $placeholderMap);
                    }

                    $params[] = ['type' => 'text', 'text' => $resolved];
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
}
