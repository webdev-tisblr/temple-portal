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
                ->form([
                    \Filament\Forms\Components\TextInput::make('test_recipient')
                        ->label('Recipient')
                        ->helperText('Email for the email channel; phone (E.164 or 10-digit) for WhatsApp / SMS.')
                        ->required(),
                ])
                ->action(function (array $data) {
                    $template = $this->record;
                    $context = $this->buildDemoContext($template, $data['test_recipient'] ?? null);

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
     * Decode the stored `wa_components` + `placeholder_map` back into
     * the flat `wa_vars` form state so the auto-detected UI shows each
     * existing value next to the right slot.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (($data['channel'] ?? null) === NotificationTemplate::CHANNEL_WHATSAPP
            && ! empty($data['wa_template_name'])
        ) {
            $slots = WhatsAppTemplateBlueprint::slotsFor($data['wa_template_name']);
            $data['wa_vars'] = WhatsAppTemplateBlueprint::valuesFromComponents(
                $slots,
                $data['wa_components'] ?? [],
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
     *   2. Auto-fill `placeholder_map` from the registry so the driver
     *      can resolve every token to its dot-path. Admins never edit
     *      this directly anymore.
     */
    public static function serialiseTemplate(array $data): array
    {
        // Always rebuild placeholder_map from the registry. Single source
        // of truth — registry change in code propagates automatically.
        $data['placeholder_map'] = self::buildPlaceholderMap($data['key'] ?? null);

        if (($data['channel'] ?? null) === NotificationTemplate::CHANNEL_WHATSAPP) {
            $templateName = $data['wa_template_name'] ?? null;
            $values = $data['wa_vars'] ?? [];

            if ($templateName) {
                $slots = WhatsAppTemplateBlueprint::slotsFor($templateName);
                $data['wa_components'] = WhatsAppTemplateBlueprint::componentsFromValues($slots, $values);
            } else {
                $data['wa_components'] = [];
            }
        }

        // wa_vars is a UI-only scratch field — never persist it.
        unset($data['wa_vars']);

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
            'trust_name' => SystemSetting::getValue('trust_name', 'Shree Pataliya Hanumanji Seva Trust'),
        ];
    }
}
