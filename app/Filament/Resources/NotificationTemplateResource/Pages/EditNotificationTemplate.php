<?php

declare(strict_types=1);

namespace App\Filament\Resources\NotificationTemplateResource\Pages;

use App\Filament\Resources\NotificationTemplateResource;
use App\Models\Devotee;
use App\Models\NotificationTemplate;
use App\Models\SystemSetting;
use App\Services\Notifications\NotificationRegistry;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\WhatsAppTemplateBlueprint;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditNotificationTemplate extends EditRecord
{
    protected static string $resource = NotificationTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Send a test instance to a chosen address/phone. Lets admins
            // dry-run a template without firing the underlying domain event.
            Actions\Action::make('send_test')
                ->label('Send test')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                // G12 (2026-08-09): "test" sends a REAL email / WhatsApp /
                // SMS to an arbitrary operator-supplied address or phone —
                // i.e. it spends live BSP credit and can be pointed at any
                // recipient. Gated on `send_announcement` like every other
                // outbound-message action.
                ->visible(fn (): bool => auth('admin')->user()?->can('send_announcement') ?? false)
                ->form([
                    \Filament\Forms\Components\TextInput::make('test_recipient')
                        ->label('Recipient')
                        ->helperText('Email for the email channel; phone (E.164 or 10-digit) for WhatsApp / SMS.')
                        ->required(),
                    \Filament\Forms\Components\Select::make('test_language')
                        ->label('Language variant')
                        ->options(['gu' => 'ગુજરાતી', 'hi' => 'हिन्दी', 'en' => 'English'])
                        ->default('gu')
                        ->visible(fn () => $this->record->channel === NotificationTemplate::CHANNEL_WHATSAPP)
                        ->helperText('Which language variant to send (falls back to Gujarati if not configured).'),
                ])
                ->action(function (array $data) {
                    $template = $this->record;
                    $context = $this->buildDemoContext($template, $data['test_recipient'] ?? null);
                    $context['locale'] = $data['test_language'] ?? 'gu';

                    $ok = app(NotificationService::class)->sendTemplate($template, $context);

                    Notification::make()
                        ->title($ok ? 'Test dispatched' : 'Test failed')
                        ->body($ok
                            ? 'Driver accepted the message. Check the inbox / device.'
                            : 'Driver rejected the message — see laravel.log for the reason.')
                        ->color($ok ? 'success' : 'danger')
                        ->send();
                }),
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Decode the stored `wa_variants` back into the per-language tab
     * state (`wa_variant_pick.{locale}` + `wa_vars_{locale}` bags) so
     * the auto-detected UI shows each existing value next to the right
     * slot. Rows saved before wa_variants existed are seeded into the
     * tab matching their stored language code (lazy migration — the
     * next save writes wa_variants).
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (($data['channel'] ?? null) === NotificationTemplate::CHANNEL_WHATSAPP) {
            $data = self::hydrateWaVariantState($data);
        }
        return $data;
    }

    /** Shared by Edit (fill) — expands wa_variants/legacy columns into tab state. */
    public static function hydrateWaVariantState(array $data): array
    {
        $variants = is_array($data['wa_variants'] ?? null) ? $data['wa_variants'] : [];

        if ($variants === [] && ! empty($data['wa_template_name'])) {
            // Legacy single-template row → seed the tab matching its
            // language code (gu*/hi* → that tab, everything else → en).
            $lang = (string) ($data['wa_template_language'] ?? 'en');
            $tab = str_starts_with($lang, 'gu') ? 'gu' : (str_starts_with($lang, 'hi') ? 'hi' : 'en');
            $variants = [$tab => [
                'template_name' => $data['wa_template_name'],
                'language_code' => $lang,
                'components' => $data['wa_components'] ?? [],
            ]];
        }

        foreach ($variants as $locale => $variant) {
            if (! in_array($locale, ['gu', 'hi', 'en'], true)
                || ! is_array($variant)
                || empty($variant['template_name'])
            ) {
                continue;
            }
            $language = (string) ($variant['language_code'] ?? '');
            $data['wa_variant_pick'][$locale] = $variant['template_name'].'|'.$language;
            $data['wa_vars_'.$locale] = WhatsAppTemplateBlueprint::valuesFromComponents(
                WhatsAppTemplateBlueprint::slotsFor($variant['template_name'], $language ?: null),
                $variant['components'] ?? [],
            );
        }

        return $data;
    }

    /** Apply the same serialisation as Create — see Create page. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return self::serialiseTemplate($data);
    }

    /**
     * Shared serialiser used by both Create and Edit pages:
     *
     *   1. WhatsApp channel — convert the flat `wa_vars` admin input
     *      into the nested `wa_components` JSON the driver consumes.
     *   2. MERGE `placeholder_map` from the registry on top of whatever
     *      the row already had. Existing entries (from the seeder or a
     *      previous save) win; registry defaults fill only the gaps.
     *      This is the critical "preserve admin intent" rule — earlier
     *      versions rebuilt the map from scratch and clobbered seeded
     *      paths like `booking.seva.name_gu` with the registry's looser
     *      `booking.seva.name`, which doesn't exist on the Seva model
     *      and rendered every seva-booking email body as empty strings.
     */
    public static function serialiseTemplate(array $data): array
    {
        $existing = is_array($data['placeholder_map'] ?? null) ? $data['placeholder_map'] : [];
        $fromRegistry = self::buildPlaceholderMap($data['key'] ?? null);

        // Existing wins on key collision — admins/seeders can override
        // any registry default, the registry only seeds defaults.
        $data['placeholder_map'] = $existing + $fromRegistry;

        if (($data['channel'] ?? null) === NotificationTemplate::CHANNEL_WHATSAPP) {
            $variants = [];

            foreach (['gu', 'hi', 'en'] as $locale) {
                $composite = $data['wa_variant_pick'][$locale] ?? null;
                if (! is_string($composite) || $composite === '') {
                    continue;
                }
                [$name, $language] = array_pad(explode('|', $composite, 2), 2, null);
                if (! $name) {
                    continue;
                }
                $slots = WhatsAppTemplateBlueprint::slotsFor($name, $language ?: null);
                $variants[$locale] = [
                    'template_name' => $name,
                    'language_code' => $language ?: 'en',
                    'components' => WhatsAppTemplateBlueprint::componentsFromValues(
                        $slots,
                        $data['wa_vars_'.$locale] ?? [],
                    ),
                ];
            }

            if ($variants === []) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'wa_variant_pick.gu' => 'Pick an approved WhatsApp template for at least one language (Gujarati recommended — it is the fallback).',
                ]);
            }

            $data['wa_variants'] = $variants;

            // Mirror the Gujarati (or first configured) variant into the
            // legacy columns — the reminder-config preview lookup, old
            // log retries and emptiness checks still read them.
            $primary = $variants['gu'] ?? reset($variants);
            $data['wa_template_name'] = $primary['template_name'];
            $data['wa_template_language'] = $primary['language_code'];
            $data['wa_components'] = $primary['components'];
        }

        // UI-only scratch fields — never persist them.
        unset(
            $data['wa_vars'],
            $data['wa_variant_pick'],
            $data['wa_vars_gu'],
            $data['wa_vars_hi'],
            $data['wa_vars_en'],
        );

        return $data;
    }

    /** Token → dot-path map derived from the registry entry for $key. */
    private static function buildPlaceholderMap(?string $key): array
    {
        if (! $key) return [];
        $info = NotificationRegistry::describe($key);
        if (! $info) return [];

        $map = [];
        foreach ($info['placeholders'] as $token => $desc) {
            if (preg_match('/\(([^)]+)\)\s*$/', (string) $desc, $m)) {
                $map[$token] = trim($m[1]);
            } else {
                // No dot-path in the description — fall back to using
                // the token name itself as a top-level context key.
                // Works for things like `trust_name`, `otp`, `name` etc.
                $map[$token] = $token;
            }
        }
        return $map;
    }

    /**
     * Build a minimal context the configured placeholders can resolve
     * against — used only by the "Send test" action so admins can dry-
     * run a template without firing a real domain event.
     */
    private function buildDemoContext(NotificationTemplate $template, ?string $recipient): array
    {
        if ($recipient) {
            $template->recipient_strategy = in_array($template->channel, [
                NotificationTemplate::CHANNEL_WHATSAPP,
                NotificationTemplate::CHANNEL_SMS,
            ], true)
                ? NotificationTemplate::RECIPIENT_FIXED_PHONE
                : NotificationTemplate::RECIPIENT_FIXED_EMAIL;
            $template->recipient_value = $recipient;
        }

        $devotee = Devotee::query()->orderByDesc('id')->first()
            ?? new Devotee([
                'name' => 'Test Devotee',
                'email' => $recipient,
                'phone' => $recipient,
            ]);

        return [
            'devotee' => $devotee,
            'donation' => [
                'amount' => '1,100',
                'receipt_number' => 'TEST/0001',
                'devotee' => $devotee,
                'created_at' => now()->format('d M Y'),
                'donation_type' => ['name' => 'સામાન્ય'],
            ],
            'booking' => [
                'devotee' => $devotee,
                'seva' => ['name' => 'મહાભિષેક પૂજા'],
                'hall' => ['name' => 'મંદિર સભા હૉલ'],
                'booking_date' => now()->addDay()->format('d M Y'),
                'slot_time' => '10:00',
                'booking_type' => 'full_day',
                'total_amount' => '5,100',
                'booking_number' => 'TEST/HALL-0001',
                'contact_name' => $devotee->name ?? 'Test Devotee',
            ],
            'order' => [
                'devotee' => $devotee,
                'order_number' => 'TEST/ORD-0001',
                'total_amount' => '501',
                'items_count' => 2,
            ],
            'submission' => [
                'name' => 'Test Submitter',
                'phone' => '+91 99999 99999',
                'email' => 'demo@example.com',
                'subject' => 'Demo subject',
                'message' => 'Demo message body.',
            ],
            'receipt' => [
                'receipt_number' => 'TEST/80G/0001',
                'amount' => '5,100',
                'amount_formatted' => '5,100.00',
                'fiscal_year' => '2025-26',
            ],
            'donor_name' => $devotee->name ?? 'Test Devotee',
            'amount' => '5,100',
            'amount_formatted' => '5,100.00',
            'receipt_pdf_url' => 'https://example.com/test-receipt.pdf',
            'greeting_card_url' => 'https://example.com/test-card.png',
            'otp' => '654321',
            'expires_in_minutes' => 5,
            'name' => $devotee->name ?? 'Test Devotee',
            'language' => 'gu',
            'trust_name' => SystemSetting::getValue('trust_name', 'Shree Patadiya Hanumanji Seva Trust'),
        ];
    }
}
