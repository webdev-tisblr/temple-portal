<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\WhatsAppTemplateCache;

/**
 * Inspects a synced WhatsApp Cloud API template and produces:
 *
 *   1. A flat list of "variable slots" — one entry per real {{n}} the
 *      template uses, plus one entry per header-media link (DOCUMENT /
 *      IMAGE / VIDEO) and per dynamic button URL placeholder. Drives
 *      the auto-detected Filament UI on the NotificationTemplate edit
 *      screen.
 *
 *   2. A Meta-compatible `components` payload built from the slot
 *      values the admin filled in — the same JSON shape the
 *      WhatsAppNotificationDriver consumes verbatim (so the driver
 *      never had to change).
 *
 *   3. The inverse — parsing an existing `wa_components` array back
 *      into slot-keyed values for re-editing.
 *
 * The single source of truth for "which variables exist" is the
 * synced Meta template; the admin can't add/remove variables, they
 * can only choose what value fills each one. That eliminates the
 * old nested-repeater UX entirely.
 */
final class WhatsAppTemplateBlueprint
{
    /**
     * Build the slot list for the named (synced) template.
     *
     * Returns rows of:
     *   [
     *     'key'         => 'body.1',          // stable id; used as form key
     *     'section'     => 'body',            // header | body | button
     *     'sub_type'    => null,              // 'url' for URL buttons
     *     'button_index'=> null,              // 0-based button position
     *     'slot'        => 1,                 // the {{n}} number
     *     'param_type'  => 'text',            // text | image | document | video
     *     'label'       => 'Body {{1}}',
     *     'help'        => 'In: "Hi {{1}}, your donation…"',
     *     'is_filename' => false,             // true for the document filename row
     *   ]
     *
     * @return array<int, array<string, mixed>>
     */
    public static function slotsFor(string $templateName, ?string $language = null): array
    {
        $cache = WhatsAppTemplateCache::query()
            ->where('name', $templateName)
            ->when($language, fn ($q) => $q->where('language', $language))
            ->orderBy('id')
            ->first();

        if (! $cache) return [];

        return self::slotsFromComponents($cache->components ?? []);
    }

    /**
     * Parse Meta's verbatim `components` shape into slot rows.
     *
     * @param  array<int, array<string, mixed>>  $components
     * @return array<int, array<string, mixed>>
     */
    public static function slotsFromComponents(array $components): array
    {
        $slots = [];

        foreach ($components as $component) {
            $type = strtolower((string) ($component['type'] ?? ''));

            if ($type === 'header') {
                $format = strtolower((string) ($component['format'] ?? 'text'));

                if (in_array($format, ['document', 'image', 'video'], true)) {
                    // Media header — needs a public URL. Slot keys use
                    // underscores so Filament doesn't parse them as
                    // nested state paths.
                    $slots[] = [
                        'key' => 'header_link',
                        'section' => 'header',
                        'sub_type' => null,
                        'button_index' => null,
                        'slot' => 1,
                        'param_type' => $format,
                        'label' => 'Header — ' . strtoupper($format) . ' link',
                        'help' => 'Public URL the recipient can fetch (presigned URLs are fine).',
                        'is_filename' => false,
                    ];

                    if ($format === 'document') {
                        $slots[] = [
                            'key' => 'header_filename',
                            'section' => 'header',
                            'sub_type' => null,
                            'button_index' => null,
                            'slot' => 1,
                            'param_type' => 'document',
                            'label' => 'Header — Document filename',
                            'help' => 'Filename shown to the recipient, e.g. 80G_Receipt.pdf.',
                            'is_filename' => true,
                        ];
                    }
                } elseif ($format === 'text') {
                    // Text header — count {{n}} occurrences in the header text.
                    $text = (string) ($component['text'] ?? '');
                    foreach (self::findPlaceholders($text) as $slot) {
                        $slots[] = [
                            'key' => "header_{$slot}",
                            'section' => 'header',
                            'sub_type' => null,
                            'button_index' => null,
                            'slot' => $slot,
                            'param_type' => 'text',
                            'label' => "Header {{{$slot}}}",
                            'help' => self::snippetAround($text, $slot),
                            'is_filename' => false,
                        ];
                    }
                }
                continue;
            }

            if ($type === 'body') {
                $text = (string) ($component['text'] ?? '');
                foreach (self::findPlaceholders($text) as $slot) {
                    $slots[] = [
                        'key' => "body_{$slot}",
                        'section' => 'body',
                        'sub_type' => null,
                        'button_index' => null,
                        'slot' => $slot,
                        'param_type' => 'text',
                        'label' => "Body {{{$slot}}}",
                        'help' => self::snippetAround($text, $slot),
                        'is_filename' => false,
                    ];
                }
                continue;
            }

            if ($type === 'buttons') {
                $buttons = $component['buttons'] ?? [];
                foreach ($buttons as $idx => $button) {
                    $btnType = strtolower((string) ($button['type'] ?? ''));

                    // Only URL buttons carry placeholders — quick_reply
                    // and phone_number buttons are static labels.
                    if ($btnType !== 'url') continue;

                    $url = (string) ($button['url'] ?? '');
                    $btnText = (string) ($button['text'] ?? "Button {$idx}");

                    foreach (self::findPlaceholders($url) as $slot) {
                        $slots[] = [
                            'key' => "button_{$idx}_{$slot}",
                            'section' => 'button',
                            'sub_type' => 'url',
                            'button_index' => $idx,
                            'slot' => $slot,
                            'param_type' => 'text',
                            'label' => "Button \"{$btnText}\" URL {{{$slot}}}",
                            'help' => "Dynamic part of: {$url}",
                            'is_filename' => false,
                        ];
                    }
                }
                continue;
            }
            // footer / unrecognised — no variables.
        }

        return $slots;
    }

    /**
     * Convert the flat slot-keyed admin input into Meta's nested
     * `components` payload. The output drops into NotificationTemplate
     * `wa_components` JSON column and is consumed verbatim by the
     * WhatsAppNotificationDriver.
     *
     * @param  array<int, array<string, mixed>>  $slots    From slotsFor()
     * @param  array<string, string>             $values   slot.key => entered value (literal or {{ token }})
     * @return array<int, array<string, mixed>>
     */
    public static function componentsFromValues(array $slots, array $values): array
    {
        // Group slots by (section, sub_type, button_index) so each
        // component row carries its parameters in {{n}} order.
        $grouped = [];
        foreach ($slots as $slot) {
            $section = $slot['section'];
            $subType = $slot['sub_type'];
            $btnIdx = $slot['button_index'];
            $groupKey = $section . '|' . ($subType ?? '') . '|' . ($btnIdx ?? '');

            $grouped[$groupKey] ??= [
                'section' => $section,
                'sub_type' => $subType,
                'button_index' => $btnIdx,
                'rows' => [],
            ];
            $grouped[$groupKey]['rows'][] = $slot;
        }

        $components = [];

        foreach ($grouped as $group) {
            $component = ['type' => $group['section']];
            if ($group['sub_type']) $component['sub_type'] = $group['sub_type'];
            if ($group['button_index'] !== null) $component['index'] = (int) $group['button_index'];

            // Index document-filename rows by their slot so we can
            // weld them onto the matching media row.
            $byKey = [];
            foreach ($group['rows'] as $row) {
                $byKey[$row['key']] = $row;
            }

            $parameters = [];
            foreach ($group['rows'] as $row) {
                if ($row['is_filename']) continue; // attached to the link row

                $rawValue = trim((string) ($values[$row['key']] ?? ''));
                if ($rawValue === '') continue;

                $isMedia = in_array($row['param_type'], ['image', 'document', 'video'], true);

                if ($isMedia) {
                    // Strip {{ }} if admin chose a registry token; store
                    // verbatim. Driver runs it back through the
                    // placeholder_map to resolve a real URL at send time.
                    $token = self::extractToken($rawValue);
                    $param = ['type' => $row['param_type']];

                    if ($token !== null) {
                        // Tokenised — driver resolves dot-path from placeholder_map.
                        $param['value_token'] = $token;
                    } else {
                        // Literal URL.
                        $param['value'] = $rawValue;
                    }

                    if ($row['param_type'] === 'document') {
                        $filenameKey = str_replace('_link', '_filename', $row['key']);
                        $filename = trim((string) ($values[$filenameKey] ?? ''));
                        if ($filename !== '') $param['filename'] = $filename;
                    }
                    $parameters[] = $param;
                    continue;
                }

                // Text param.
                $token = self::extractToken($rawValue);
                $parameters[] = $token !== null
                    ? ['type' => 'text', 'value_token' => $token]
                    : ['type' => 'text', 'value' => $rawValue];
            }

            if ($parameters !== []) $component['parameters'] = $parameters;

            $components[] = $component;
        }

        return $components;
    }

    /**
     * Reverse the conversion — turn a stored `wa_components` array
     * back into slot-keyed values so the admin can re-edit. The slot
     * skeleton comes from the (current) Meta template; existing
     * values are matched into it positionally.
     *
     * @param  array<int, array<string, mixed>>  $slots
     * @param  array<int, array<string, mixed>>  $components
     * @return array<string, string>
     */
    public static function valuesFromComponents(array $slots, array $components): array
    {
        // Build lookup: (section, sub_type, button_index, position) -> param
        $params = [];
        foreach ($components as $component) {
            $section = strtolower((string) ($component['type'] ?? ''));
            $subType = $component['sub_type'] ?? null;
            $btnIdx = $component['index'] ?? null;
            $componentParams = $component['parameters'] ?? [];

            foreach ($componentParams as $position => $p) {
                $params[$section . '|' . ($subType ?? '') . '|' . ($btnIdx ?? '') . '|' . $position] = $p;
            }
        }

        // Walk slots in order, tracking position within each group.
        $values = [];
        $positionWithin = [];
        foreach ($slots as $slot) {
            if ($slot['is_filename']) {
                // Filename comes off the matching link param.
                $linkKey = str_replace('_filename', '_link', $slot['key']);
                $values[$slot['key']] = $values[$linkKey . ':filename'] ?? '';
                continue;
            }

            $groupKey = $slot['section'] . '|' . ($slot['sub_type'] ?? '') . '|' . ($slot['button_index'] ?? '');
            $positionWithin[$groupKey] ??= 0;
            $pos = $positionWithin[$groupKey]++;

            $param = $params[$groupKey . '|' . $pos] ?? null;
            if (! is_array($param)) {
                $values[$slot['key']] = '';
                continue;
            }

            $isMedia = in_array($slot['param_type'], ['image', 'document', 'video'], true);
            if ($isMedia) {
                $values[$slot['key']] = isset($param['value_token'])
                    ? '{{ ' . $param['value_token'] . ' }}'
                    : (string) ($param['value'] ?? ($param[$slot['param_type']]['link'] ?? ''));
                if ($slot['param_type'] === 'document' && ! empty($param['filename'])) {
                    $values[$slot['key'] . ':filename'] = (string) $param['filename'];
                }
            } else {
                $values[$slot['key']] = isset($param['value_token'])
                    ? '{{ ' . $param['value_token'] . ' }}'
                    : (string) ($param['value'] ?? ($param['text'] ?? ''));
            }
        }

        // Filename keys we stashed above get promoted to real slot
        // keys in the second pass above; remove the intermediate.
        foreach (array_keys($values) as $k) {
            if (str_contains($k, ':filename')) unset($values[$k]);
        }

        return $values;
    }

    /**
     * Distinct {{n}} numbers appearing in $text, in numeric order.
     *
     * @return array<int>
     */
    public static function findPlaceholders(string $text): array
    {
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $text, $m);
        $slots = array_unique(array_map('intval', $m[1] ?? []));
        sort($slots);
        return array_values($slots);
    }

    /**
     * If the admin's value is exactly "{{ token }}" (whitespace-tolerant),
     * return "token". Otherwise null. Lets the form treat tokens and
     * literal text the same way at the input layer.
     */
    public static function extractToken(string $value): ?string
    {
        if (preg_match('/^\{\{\s*([a-zA-Z0-9_\.]+)\s*\}\}$/', trim($value), $m)) {
            return $m[1];
        }
        return null;
    }

    /** Short context excerpt around a {{n}} placeholder, for the slot's help text. */
    private static function snippetAround(string $text, int $slot): string
    {
        $pattern = '/.{0,30}\{\{\s*' . $slot . '\s*\}\}.{0,30}/';
        if (preg_match($pattern, $text, $m)) {
            $snippet = trim($m[0]);
            return 'In: "…' . $snippet . '…"';
        }
        return '';
    }
}
