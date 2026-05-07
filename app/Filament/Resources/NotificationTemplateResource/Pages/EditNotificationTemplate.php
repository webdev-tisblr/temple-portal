<?php

declare(strict_types=1);

namespace App\Filament\Resources\NotificationTemplateResource\Pages;

use App\Filament\Resources\NotificationTemplateResource;
use App\Models\Devotee;
use App\Models\NotificationTemplate;
use App\Models\SystemSetting;
use App\Services\Notifications\NotificationService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditNotificationTemplate extends EditRecord
{
    protected static string $resource = NotificationTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Send a test instance of this template to the trust admin
            // (or a specified address). The dispatch context is a small
            // demo bundle keyed by the registry, so admins can preview
            // their template without firing a real domain event.
            Actions\Action::make('send_test')
                ->label('Send test')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->form([
                    \Filament\Forms\Components\TextInput::make('test_recipient')
                        ->label('Recipient')
                        ->helperText('Email for email channel; phone (E.164 or 10-digit) for WhatsApp; ignored for push.')
                        ->required(fn () => $this->record->channel !== NotificationTemplate::CHANNEL_PUSH),
                ])
                ->action(function (array $data) {
                    $template = $this->record;
                    $context = $this->buildDemoContext($template, $data['test_recipient'] ?? null);

                    $ok = app(NotificationService::class)->sendTemplate($template, $context);

                    Notification::make()
                        ->title($ok ? 'Test dispatched' : 'Test failed')
                        ->body($ok
                            ? 'Driver accepted the message. Check the inbox / device.'
                            : 'Driver rejected the message — see logs for the reason.')
                        ->color($ok ? 'success' : 'danger')
                        ->send();
                }),
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Build a minimal context the configured placeholders can resolve
     * against — used only by the "Send test" action so admins can dry-
     * run a template without firing a real domain event.
     */
    private function buildDemoContext(NotificationTemplate $template, ?string $recipient): array
    {
        // Override the recipient strategy temporarily so the test goes
        // to the address admins typed into the form regardless of the
        // template's saved strategy.
        if ($recipient) {
            $template->recipient_strategy = $template->channel === NotificationTemplate::CHANNEL_WHATSAPP
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
                'fiscal_year' => '2025-26',
            ],
            'otp' => '123456',
            'expires_in_minutes' => 5,
            'trust_name' => SystemSetting::getValue('trust_name', 'Shree Pataliya Hanumanji Seva Trust'),
        ];
    }
}
