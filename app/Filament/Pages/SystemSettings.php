<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\SystemSetting;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Mail;

class SystemSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationGroup = 'System';
    protected static ?string $title = 'System Settings';
    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.system-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $settings = SystemSetting::all()->pluck('value', 'key')->toArray();
        $this->form->fill($settings);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Tabs::make('Settings')->tabs([

                // ─── Tab 1: General Settings ───
                Forms\Components\Tabs\Tab::make('General Settings')
                    ->icon('heroicon-o-building-library')
                    ->schema([
                        Forms\Components\Section::make('Trust Details')->schema([
                            Forms\Components\TextInput::make('trust_name')->label('Trust Name')->required(),
                            Forms\Components\Textarea::make('trust_address')->label('Address')->rows(2),
                            Forms\Components\TextInput::make('trust_pan')->label('Trust PAN'),
                            Forms\Components\TextInput::make('trust_phone')->label('Phone'),
                            Forms\Components\TextInput::make('trust_email')->label('Email'),
                        ])->columns(2),

                        Forms\Components\Section::make('80G Details')->schema([
                            Forms\Components\TextInput::make('trust_80g_reg_no')->label('80G Registration No.'),
                            Forms\Components\TextInput::make('trust_80g_validity')->label('80G Validity Period'),
                            Forms\Components\TextInput::make('receipt_prefix')->label('Receipt Prefix')->default('SPHST/80G'),
                        ])->columns(2),

                        Forms\Components\Section::make('General')->schema([
                            Forms\Components\TextInput::make('youtube_live_url')->label('YouTube Live URL')->url(),
                            Forms\Components\TextInput::make('youtube_channel_id')->label('YouTube Channel ID'),
                            Forms\Components\Select::make('default_language')->label('Default Language')
                                ->options(['gu' => 'Gujarati', 'hi' => 'Hindi', 'en' => 'English'])->default('gu'),
                        ])->columns(2),
                    ]),

                // ─── Tab 2: Integrations ───
                Forms\Components\Tabs\Tab::make('Integrations')
                    ->icon('heroicon-o-puzzle-piece')
                    ->schema([

                        // Payment Gateway
                        Forms\Components\Section::make('Payment Gateway — Razorpay')
                            ->icon('heroicon-o-credit-card')
                            ->schema([
                                Forms\Components\Toggle::make('razorpay_test_mode')->label('Test Mode')
                                    ->helperText('Enable for testing with Razorpay test keys.')->default(true),
                                Forms\Components\TextInput::make('razorpay_key_id')->label('Key ID')
                                    ->placeholder('rzp_test_...'),
                                Forms\Components\TextInput::make('razorpay_key_secret')->label('Key Secret')
                                    ->password()->revealable()->placeholder('Enter secret key'),
                                Forms\Components\TextInput::make('razorpay_webhook_secret')->label('Webhook Secret')
                                    ->password()->revealable()->placeholder('Enter webhook secret'),
                            ])->columns(2)->collapsible(),

                        // Email / SMTP
                        Forms\Components\Section::make('Email / SMTP')
                            ->icon('heroicon-o-envelope')
                            ->description('Configure SMTP settings to send emails (receipts, notifications, etc.).')
                            ->schema([
                                Forms\Components\Select::make('mail_driver')->label('Mail Driver')
                                    ->options([
                                        'smtp' => 'SMTP',
                                        'log' => 'Log (testing only)',
                                    ])->default('log')
                                    ->helperText('Use "Log" for local testing. Switch to "SMTP" for production.'),
                                Forms\Components\TextInput::make('mail_host')->label('SMTP Host')
                                    ->placeholder('smtp.gmail.com'),
                                Forms\Components\TextInput::make('mail_port')->label('SMTP Port')
                                    ->placeholder('587')->numeric(),
                                Forms\Components\Select::make('mail_encryption')->label('Encryption')
                                    ->options(['tls' => 'TLS', 'ssl' => 'SSL', '' => 'None'])->default('tls'),
                                Forms\Components\TextInput::make('mail_username')->label('Username / Email')
                                    ->placeholder('noreply@temple.org'),
                                Forms\Components\TextInput::make('mail_password')->label('Password / App Password')
                                    ->password()->revealable()->placeholder('Enter password or app password'),
                                Forms\Components\TextInput::make('mail_from_address')->label('From Address')
                                    ->email()->placeholder('noreply@temple.org'),
                                Forms\Components\TextInput::make('mail_from_name')->label('From Name')
                                    ->placeholder('Shree Pataliya Hanumanji Seva Trust'),
                                Forms\Components\TextInput::make('mail_test_recipient')->label('Test Recipient Email')
                                    ->email()->placeholder('your@email.com')
                                    ->helperText('Enter an email and click "Test SMTP" to verify your settings.'),
                                Forms\Components\Actions::make([
                                    Forms\Components\Actions\Action::make('test_smtp')
                                        ->label('Send Test Email')
                                        ->icon('heroicon-o-paper-airplane')
                                        ->color('success')
                                        ->action(function (Forms\Get $get) {
                                            $this->testSmtp($get('mail_test_recipient'));
                                        }),
                                ]),
                            ])->columns(2)->collapsible(),

                        // WhatsApp Cloud API
                        Forms\Components\Section::make('WhatsApp Cloud API — Meta')
                            ->icon('heroicon-o-chat-bubble-left-right')
                            ->description('Configure Meta Cloud API credentials to send WhatsApp messages (OTP, receipts, notifications).')
                            ->schema([
                                Forms\Components\TextInput::make('whatsapp_api_url')->label('API Base URL')
                                    ->placeholder('https://graph.facebook.com/v21.0')
                                    ->default('https://graph.facebook.com/v21.0')
                                    ->helperText('Meta Graph API URL with version.'),
                                Forms\Components\TextInput::make('whatsapp_access_token')->label('Permanent Access Token')
                                    ->password()->revealable()
                                    ->placeholder('EAAxxxxxxx...')
                                    ->helperText('System user token from Meta Business Suite.'),
                                Forms\Components\TextInput::make('whatsapp_phone_number_id')->label('Phone Number ID')
                                    ->placeholder('1234567890...')
                                    ->helperText('From WhatsApp > API Setup in Meta Business.'),
                                Forms\Components\TextInput::make('whatsapp_waba_id')->label('WhatsApp Business Account ID')
                                    ->placeholder('1234567890...')
                                    ->helperText('WABA ID from Meta Business Settings.'),
                                Forms\Components\TextInput::make('whatsapp_business_id')->label('Business ID')
                                    ->placeholder('1234567890...')
                                    ->helperText('Meta Business ID from Business Settings > Business Info.'),
                                Forms\Components\TextInput::make('whatsapp_webhook_verify_token')->label('Webhook Verify Token')
                                    ->password()->revealable()
                                    ->placeholder('Enter a custom verify token')
                                    ->helperText('Token you set when configuring webhooks in Meta.'),
                            ])->columns(2)->collapsible(),

                    ]),

            ])->persistTabInQueryString(),
        ])->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $groups = [
            'trust_' => 'trust',
            'receipt_' => 'payment',
            'razorpay_' => 'payment',
            'mail_' => 'mail',
            'whatsapp_' => 'whatsapp',
        ];

        foreach ($data as $key => $value) {
            $group = 'general';
            foreach ($groups as $prefix => $g) {
                if (str_starts_with($key, $prefix)) {
                    $group = $g;
                    break;
                }
            }

            SystemSetting::updateOrCreate(
                ['key' => $key],
                ['value' => $value ?? '', 'group' => $group, 'updated_at' => now()]
            );
        }

        Notification::make()->title('Settings saved successfully')->success()->send();
    }

    public function testSmtp(?string $recipient = null): void
    {
        if (empty($recipient)) {
            Notification::make()->title('Enter a recipient email first.')->warning()->send();
            return;
        }

        // Apply current form values to mail config on-the-fly
        $data = $this->form->getState();

        $driver = $data['mail_driver'] ?? 'log';
        if ($driver !== 'smtp') {
            Notification::make()
                ->title('Mail driver is set to "' . $driver . '". Switch to SMTP to test.')
                ->warning()->send();
            return;
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $data['mail_host'] ?? '',
            'mail.mailers.smtp.port' => (int) ($data['mail_port'] ?? 587),
            'mail.mailers.smtp.encryption' => $data['mail_encryption'] ?: null,
            'mail.mailers.smtp.username' => $data['mail_username'] ?? '',
            'mail.mailers.smtp.password' => $data['mail_password'] ?? '',
            'mail.from.address' => $data['mail_from_address'] ?? $data['mail_username'] ?? '',
            'mail.from.name' => $data['mail_from_name'] ?? 'Temple Portal',
        ]);

        // Purge the cached SMTP transport so it picks up new config
        Mail::purge('smtp');

        try {
            Mail::raw(
                "This is a test email from Shree Pataliya Hanumanji Seva Trust Digital Portal.\n\nIf you received this, your SMTP settings are working correctly.",
                function ($message) use ($recipient, $data) {
                    $message->to($recipient)
                        ->subject('SMTP Test — Temple Portal');
                }
            );

            Notification::make()
                ->title('Test email sent to ' . $recipient)
                ->success()->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('SMTP test failed')
                ->body($e->getMessage())
                ->danger()->send();
        }
    }
}
