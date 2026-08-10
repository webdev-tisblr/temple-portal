<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Msg91WebhookEvent;
use App\Models\SystemSetting;
use App\Services\SmsService;
use App\Services\WhatsAppService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;

class SystemSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationGroup = 'System';
    protected static ?string $title = 'System Settings';
    protected static ?string $navigationLabel = 'Settings';
    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.pages.system-settings';

    public static function canAccess(): bool
    {
        return auth('admin')->user()?->can('page_SystemSettings') ?? false;
    }

    /**
     * Form keys that are DISPLAY ONLY — derived at render time and never
     * written back to temple_system_settings.
     *
     * sms_msg91_webhook_url is built from the token plus APP_URL. Saving a
     * copy of it would create a second, silently stale source of truth the
     * moment the token is regenerated or the domain changes — and the
     * whole point of the field is that the admin can trust what it shows.
     */
    private const DISPLAY_ONLY_KEYS = [
        'sms_msg91_webhook_url',
    ];

    /**
     * Permission gate for the delivery-report webhook block. The page as a
     * whole is already gated by page_SystemSettings; the webhook URL is a
     * credential in its own right, so it carries its own permission.
     */
    public static function canManageSmsWebhook(): bool
    {
        return auth('admin')->user()?->can('manage_sms_webhook') ?? false;
    }

    public ?array $data = [];

    public function mount(): void
    {
        $settings = SystemSetting::all()->pluck('value', 'key')->toArray();

        // Derived, never stored. Generated on first read so the field is
        // never blank — see SmsService::webhookToken().
        if (static::canManageSmsWebhook()) {
            $settings['sms_msg91_webhook_url'] = SmsService::webhookUrl();
        }

        $this->form->fill($settings);
    }

    /**
     * Mint a fresh webhook token and refresh the displayed URL.
     *
     * Destructive by design: reports stop arriving until the new URL is
     * pasted into MSG91, which is why the action is confirmation-gated and
     * the flash message repeats the consequence rather than just saying
     * "done".
     */
    public function regenerateMsg91WebhookToken(): void
    {
        abort_unless(static::canManageSmsWebhook(), 403);

        SmsService::regenerateWebhookToken();
        $this->data['sms_msg91_webhook_url'] = SmsService::webhookUrl();

        Notification::make()
            ->title('New webhook URL generated')
            ->body('The previous URL no longer works. Paste the new one into MSG91 now — until you do, no SMS delivery reports will arrive and every SMS will show as unconfirmed.')
            ->warning()
            ->persistent()
            ->send();
    }

    /**
     * Last 10 delivery reports: when, masked phone, status, MSG91's reason.
     *
     * This small table is what turns "no SMS arrived" into a diagnosis —
     * MSG91's own sentence ("Template ID Missing or Invalid Template",
     * "DND number") is the thing an admin would otherwise have to log into
     * MSG91 to read. Rendered as plain HTML because a Placeholder cannot
     * host a table builder, and everything interpolated is escaped.
     */
    public function renderRecentMsg91Events(): HtmlString
    {
        if (! static::canManageSmsWebhook()) {
            return new HtmlString('');
        }

        try {
            $events = Msg91WebhookEvent::query()
                ->latest('received_at')
                ->limit(10)
                ->get();
        } catch (\Throwable $e) {
            return new HtmlString('<p class="text-sm text-danger-600">Could not read delivery reports: ' . e($e->getMessage()) . '</p>');
        }

        if ($events->isEmpty()) {
            // NOT an error state. Before the URL is pasted into MSG91 this
            // is the expected view, and it must read as "not set up yet"
            // rather than as a fault.
            return new HtmlString(
                '<div class="text-sm rounded-lg border border-warning-300 bg-warning-50 p-3 text-warning-800 '
                . 'dark:border-warning-600 dark:bg-warning-900/20 dark:text-warning-300">'
                . '<strong>No delivery reports received yet.</strong> This is normal until the webhook URL above is '
                . 'saved in MSG91. Until then every SMS stays "unconfirmed" in the notification log — the platform '
                . 'genuinely does not know whether those messages arrived.'
                . '</div>'
            );
        }

        $rows = '';
        foreach ($events as $event) {
            $status = $event->delivery_status ?? 'unknown';
            $colour = match ($status) {
                'delivered' => 'text-success-600',
                'failed' => 'text-danger-600',
                default => 'text-gray-600 dark:text-gray-400',
            };

            $rows .= '<tr class="border-t border-gray-200 dark:border-gray-700">'
                . '<td class="py-1.5 pr-3 whitespace-nowrap">' . e(optional($event->received_at)->format('d M H:i') ?? '—') . '</td>'
                // Masked at write time; masked again here would be a no-op.
                . '<td class="py-1.5 pr-3 whitespace-nowrap">' . e($event->recipient_masked ?? '—') . '</td>'
                . '<td class="py-1.5 pr-3 whitespace-nowrap ' . $colour . '">' . e(ucfirst($status)) . '</td>'
                // MSG91's wording, verbatim — the whole reason this panel exists.
                . '<td class="py-1.5">' . e($event->description ?? '—') . '</td>'
                . '</tr>';
        }

        return new HtmlString(
            '<div class="overflow-x-auto"><table class="w-full text-sm">'
            . '<thead><tr class="text-left text-gray-500 dark:text-gray-400">'
            . '<th class="pb-1 pr-3 font-medium">When</th>'
            . '<th class="pb-1 pr-3 font-medium">To</th>'
            . '<th class="pb-1 pr-3 font-medium">Status</th>'
            . '<th class="pb-1 font-medium">MSG91 reason</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>'
        );
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
                            // Name + address are shown to devotees in their
                            // chosen language (footer, contact, home visit
                            // block, app temple-info). Bare key = Gujarati;
                            // hi/en live in suffixed keys and fall back to
                            // Gujarati when left empty.
                            \App\Filament\Support\TranslatableTabs::make(fn (string $locale, string $label) => [
                                Forms\Components\TextInput::make($locale === 'gu' ? 'trust_name' : "trust_name_{$locale}")
                                    ->label("Trust Name {$label}")
                                    ->required($locale === 'gu'),
                                Forms\Components\Textarea::make($locale === 'gu' ? 'trust_address' : "trust_address_{$locale}")
                                    ->label("Address {$label}")
                                    ->rows(2),
                            ], id: 'trust_identity_translations'),
                            Forms\Components\TextInput::make('trust_pan')->label('Trust PAN'),
                            Forms\Components\TextInput::make('trust_phone')->label('Phone'),
                            Forms\Components\TextInput::make('trust_email')->label('Email'),
                            // Public footer tagline. Renders below the
                            // logo/name on every page via the footer
                            // partial. Plain text only — line breaks
                            // are honoured by Tailwind's leading-relaxed.
                            \App\Filament\Support\TranslatableTabs::make(fn (string $locale, string $label) => [
                                Forms\Components\Textarea::make($locale === 'gu' ? 'trust_tagline' : "trust_tagline_{$locale}")
                                    ->label("Footer tagline {$label}")
                                    ->rows(3)
                                    ->helperText($locale === 'gu' ? 'Short description shown under the trust logo in the public footer. 1–2 sentences.' : null),
                            ], id: 'tagline_translations'),
                        ])->columns(2),

                        Forms\Components\Section::make('Website & App Trust Info')
                            ->description('Shown on the app\'s Temple Info screen (and available to the website). Each text is per-language — empty Hindi/English falls back to Gujarati.')
                            ->collapsed()
                            ->schema([
                                \App\Filament\Support\TranslatableTabs::make(fn (string $locale, string $label) => [
                                    Forms\Components\Textarea::make($locale === 'gu' ? 'trust_about' : "trust_about_{$locale}")
                                        ->label("About the Trust {$label}")
                                        ->rows(4),
                                    Forms\Components\Textarea::make($locale === 'gu' ? 'trust_rules' : "trust_rules_{$locale}")
                                        ->label("Temple Rules {$label}")
                                        ->rows(4),
                                    Forms\Components\Textarea::make($locale === 'gu' ? 'trust_nearby' : "trust_nearby_{$locale}")
                                        ->label("Nearby Places {$label}")
                                        ->rows(3),
                                    Forms\Components\Textarea::make($locale === 'gu' ? 'trust_facilities' : "trust_facilities_{$locale}")
                                        ->label("Facilities {$label}")
                                        ->rows(3),
                                ], id: 'trust_info_translations'),
                                Forms\Components\TextInput::make('trust_map_url')
                                    ->label('Google Maps URL')
                                    ->url()
                                    ->helperText('Used by the footer "Directions" link, home Visit block and the app.'),
                                Forms\Components\TextInput::make('trust_whatsapp')
                                    ->label('Trust WhatsApp Number')
                                    ->helperText('Shown in the footer and the app temple-info screen.'),
                            ])->columns(2),

                        Forms\Components\Section::make('80G Details')->schema([
                            Forms\Components\TextInput::make('trust_80g_reg_no')->label('80G Registration No.'),
                            Forms\Components\TextInput::make('trust_80g_validity')->label('80G Validity Period'),
                            Forms\Components\TextInput::make('receipt_prefix')->label('Receipt Prefix')->default('SPHST/80G'),
                        ])->columns(2),

                        // YouTube live settings moved to Content Management →
                        // Daily Darshan → "Live darshan settings" (same
                        // SystemSetting keys — they now sit next to the
                        // darshan content they belong to).
                        Forms\Components\Section::make('General')->schema([
                            Forms\Components\Select::make('default_language')->label('Default Language')
                                ->options(['gu' => 'Gujarati', 'hi' => 'Hindi', 'en' => 'English'])->default('gu'),
                        ])->columns(2),

                        Forms\Components\Section::make('Mobile App')
                            ->icon('heroicon-o-device-phone-mobile')
                            ->description('Store links for the Patadiya Hanumanji app. These drive the "install our app" banner shown to mobile-web visitors (device-aware: iPhone → App Store, Android → Play Store) AND the force-update check the app itself reads. Leave a URL blank to hide that platform. The banner\'s on/off, frequency and schedule now live in Home Page Settings → App-Install Banner.')
                            ->schema([
                                Forms\Components\TextInput::make('app_ios_store_url')
                                    ->label('iOS App Store URL')
                                    ->url()
                                    ->placeholder('https://apps.apple.com/app/patadiya-hanumanji')
                                    ->helperText('Apple App Store listing link.'),
                                Forms\Components\TextInput::make('app_android_store_url')
                                    ->label('Android Play Store URL')
                                    ->url()
                                    ->placeholder('https://play.google.com/store/apps/details?id=com.patadiyahanumanji.app')
                                    ->helperText('Google Play Store listing link.'),
                                Forms\Components\TextInput::make('app_whatsapp_group_url')
                                    ->label('WhatsApp group invite link')
                                    ->url()
                                    ->placeholder('https://chat.whatsapp.com/XXXXXXXXXXXX')
                                    ->helperText('The trust\'s devotee WhatsApp group. Shown as a "Join our WhatsApp Group" row in the app\'s More screen.'),
                                Forms\Components\Toggle::make('app_whatsapp_group_enabled')
                                    ->label('Show "Join our WhatsApp Group" in the app')
                                    ->helperText('OFF hides the row in the app instantly — no app update needed.'),
                                Forms\Components\Toggle::make('app_scheme_enabled')
                                    ->label('"Open app" button on the website banner')
                                    ->helperText('Makes the return-to-the-app bar tappable for devotees who arrived from the app: opens the app via its patadiyahanumanji:// link (v1.4.6+), and on older builds without the link it falls back to the App Store / Play Store page after ~1.6s. Safe to keep ON.'),
                                Forms\Components\Group::make([
                                    Forms\Components\Select::make('push_notification_tone')
                                        ->label('App notification tone')
                                        // Only list tones actually bundled in the
                                        // current app build (FirebaseService has the
                                        // matching allowlist). Ghanti/Aarti rejoin
                                        // here once their clips ship in the app.
                                        ->options([
                                            'default' => 'System default',
                                            'jayshreeram' => 'Jay Shree Ram',
                                        ])
                                        // mount() only fills keys that exist in the
                                        // DB — pin blank state to 'default' so the
                                        // closed select can never DISPLAY a custom
                                        // tone that isn't actually saved.
                                        ->afterStateHydrated(function (Forms\Components\Select $component, $state) {
                                            if (blank($state)) {
                                                $component->state('default');
                                            }
                                        })
                                        ->live()
                                        ->helperText('Which sound app push notifications play. The tones are bundled inside the app — pick a custom tone only after the app build that ships them (v1.4.6+) is widely rolled out; on older Android builds, pushes sent to a tone channel are NOT shown at all.'),
                                    Forms\Components\Placeholder::make('push_tone_preview')
                                        ->hiddenLabel()
                                        ->content(fn (Forms\Get $get) => in_array($get('push_notification_tone'), ['jayshreeram'], true)
                                            ? new \Illuminate\Support\HtmlString(
                                                '<audio controls preload="none" style="height:36px;max-width:100%;" src="'
                                                .e(asset('sounds/'.$get('push_notification_tone').'.mp3'))
                                                .'"></audio>'
                                            )
                                            : new \Illuminate\Support\HtmlString('<em style="font-size:.8rem;color:#9ca3af;">System default — plays each phone\'s own notification sound.</em>')),
                                ]),
                            ])->columns(2)->collapsible(),

                        Forms\Components\Section::make('iOS Donations — App Store Compliance')
                            ->icon('heroicon-o-heart')
                            ->description('Apple guideline 3.2.2(iv): only Benevity-approved nonprofits may collect donations INSIDE an iOS app. Until the trust is approved, the iPhone app sends devotees to the website donate page instead (Android is unaffected). Turn the toggle ON only after Apple confirms our approved-nonprofit status — the app switches back to native donations instantly, no app update needed.')
                            ->collapsed()
                            ->schema([
                                Forms\Components\Toggle::make('app_ios_native_donations')
                                    ->label('Native in-app donations on iOS')
                                    ->helperText('OFF = iOS app opens the website to donate (App Store compliant). ON = native Razorpay flow inside the iOS app — ONLY after Benevity/Apple approval, or the next app review will be rejected.'),
                                Forms\Components\TextInput::make('app_donate_web_url')
                                    ->label('Donate page URL')
                                    ->url()
                                    ->placeholder('https://patadiyahanumanji.com/donate')
                                    ->helperText('Where the iOS app sends devotees to donate. Leave blank to use the default.'),
                            ])->columns(2),

                        Forms\Components\Section::make('App Store Review — Test Login')
                            ->description('A fixed OTP login for Apple/Google reviewers, who cannot receive a real WhatsApp/SMS OTP. When BOTH fields are set, this number logs in with the code below WITHOUT sending any message. It only ever accesses a throwaway test account. Leave both blank to disable. Put these values in the App Store review note.')
                            ->collapsed()
                            ->schema([
                                Forms\Components\TextInput::make('otp_test_phone')
                                    ->label('Test phone (digits only, no +)')
                                    ->helperText('e.g. 9000000000 (Indian) or 15551234567 (with country code).')
                                    ->rule(new \App\Rules\ValidPhoneNumber)
                                    ->maxLength(15),
                                Forms\Components\TextInput::make('otp_test_code')
                                    ->label('Fixed OTP code (6 digits)')
                                    ->helperText('e.g. 123456')
                                    ->rule('regex:/^\d{6}$/')
                                    ->maxLength(6),
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

                        // Cloudflare Turnstile — public form protection.
                        // Inert until BOTH keys are set: the <x-turnstile />
                        // widget renders nothing and the `turnstile` route
                        // middleware passes everything through.
                        Forms\Components\Section::make('Cloudflare Turnstile — Form Protection')
                            ->icon('heroicon-o-shield-check')
                            ->description('Free CAPTCHA on the public website forms (contact + OTP login). Create a widget at Cloudflare dashboard → Turnstile for patadiyahanumanji.com (mode: Managed) and paste both keys. Leave empty to disable.')
                            ->schema([
                                Forms\Components\TextInput::make('turnstile_site_key')->label('Site Key')
                                    ->placeholder('0x4AAA...'),
                                Forms\Components\TextInput::make('turnstile_secret_key')->label('Secret Key')
                                    ->password()->revealable()->placeholder('Enter secret key'),
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
                                    ->placeholder('Shree Patadiya Hanumanji Seva Trust'),
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

                                // Action buttons span both columns.
                                Forms\Components\Actions::make([
                                    Forms\Components\Actions\Action::make('test_whatsapp')
                                        ->label('Test Connection')
                                        ->icon('heroicon-o-wifi')
                                        ->color('primary')
                                        ->action(fn () => $this->testWhatsAppConnection()),
                                    Forms\Components\Actions\Action::make('sync_whatsapp_templates')
                                        ->label('Sync Approved Templates')
                                        ->icon('heroicon-o-arrow-path')
                                        ->color('success')
                                        ->action(fn () => $this->syncWhatsAppTemplates()),
                                ])->columnSpanFull(),
                            ])->columns(2)->collapsible(),

                        // SMS — MSG91
                        Forms\Components\Section::make('SMS — MSG91')
                            ->icon('heroicon-o-device-phone-mobile')
                            ->description('Configure MSG91 Flow API credentials. Required for SMS OTP delivery — mandatory for Play Store / App Store builds.')
                            ->schema([
                                Forms\Components\TextInput::make('sms_msg91_auth_key')
                                    ->label('Auth Key')
                                    ->password()->revealable()
                                    ->placeholder('From MSG91 dashboard → Settings → API')
                                    ->helperText('Generated under MSG91 → API menu. Treat like a password.'),
                                Forms\Components\TextInput::make('sms_msg91_sender_id')
                                    ->label('Sender ID')
                                    ->placeholder('SPHTRT')
                                    ->maxLength(6)
                                    ->helperText('6-character DLT-registered header (no spaces, letters only on most operators).'),
                                Forms\Components\TextInput::make('sms_msg91_otp_template_id')
                                    ->label('Default OTP Template ID')
                                    ->placeholder('65a1b2c3d4...')
                                    ->helperText('MSG91 template id for the OTP DLT template. Auth.otp SMS row falls back to this.'),

                                // ── DLT variable names ────────────────────
                                // MSG91 Flow fills placeholders BY NAME, not
                                // by position. Sending var1/var2 to a template
                                // whose markers are ##OTP## / ##mins## fills
                                // nothing and MSG91 rejects the submission —
                                // silently, behind an HTTP 200. That was the
                                // live cause of "no OTP SMS ever arrives".
                                // Exposed as fields so the next DLT template
                                // the trust registers cannot re-spring it.
                                Forms\Components\TextInput::make('sms_msg91_otp_var_name')
                                    ->label('OTP variable name')
                                    ->placeholder('OTP')
                                    ->maxLength(32)
                                    ->helperText('Must match the placeholder in your approved DLT template exactly. For "…is ##OTP##…" enter OTP (no ## marks). Leave blank for OTP.'),
                                Forms\Components\TextInput::make('sms_msg91_otp_validity_var_name')
                                    ->label('Validity variable name')
                                    ->placeholder('mins')
                                    ->maxLength(32)
                                    ->helperText('The placeholder holding the validity window. For "…valid for ##mins## minutes…" enter mins. Leave blank for mins.'),
                                Forms\Components\TextInput::make('sms_msg91_dlt_te_id')
                                    ->label('DLT Template Entity ID')
                                    ->placeholder('1701xxxxxxxxxxxxxxx')
                                    ->helperText('TRAI-assigned DLT Template Entity ID — included on every send for compliance routing.'),
                                Forms\Components\TextInput::make('sms_msg91_api_url')
                                    ->label('API Base URL')
                                    ->placeholder('https://control.msg91.com/api/v5')
                                    ->default('https://control.msg91.com/api/v5')
                                    ->helperText('Leave default unless MSG91 instructs otherwise.'),
                                Forms\Components\TextInput::make('sms_test_recipient')
                                    ->label('Test Recipient Mobile')
                                    ->placeholder('10-digit number, no spaces')
                                    ->helperText('Enter a number and use the buttons below to verify your setup.'),
                                Forms\Components\Actions::make([
                                    Forms\Components\Actions\Action::make('test_sms_connection')
                                        ->label('Test Connection')
                                        ->icon('heroicon-o-wifi')
                                        ->color('primary')
                                        ->action(fn () => $this->testSmsConnection()),
                                    Forms\Components\Actions\Action::make('send_test_sms')
                                        ->label('Send Test OTP')
                                        ->icon('heroicon-o-paper-airplane')
                                        ->color('success')
                                        ->action(function (Forms\Get $get) {
                                            $this->sendTestSms($get('sms_test_recipient'));
                                        }),
                                ])->columnSpanFull(),

                                // ── Delivery reports ──────────────────────
                                // The only way this platform can learn what
                                // actually happened to an SMS. Everything
                                // above it can tell you at most that MSG91
                                // accepted the request.
                                Forms\Components\Fieldset::make('Delivery reports (webhook)')
                                    ->columnSpanFull()
                                    ->visible(fn (): bool => static::canManageSmsWebhook())
                                    ->schema([
                                        Forms\Components\Placeholder::make('sms_webhook_intro')
                                            ->hiddenLabel()
                                            ->columnSpanFull()
                                            ->content(new HtmlString(
                                                '<p class="text-sm text-gray-600 dark:text-gray-400">MSG91 accepts every submission with '
                                                . '<code>{"type":"success"}</code> — including submissions with a wrong auth key or an invalid '
                                                . 'template. Nothing is checked when we send. Paste the URL below into the MSG91 dashboard '
                                                . '(<strong>Settings → Webhooks / Delivery Report URL</strong>) and MSG91 will report each message\'s real '
                                                . 'outcome back here, with its own reason for any failure.</p>'
                                            )),

                                        Forms\Components\TextInput::make('sms_msg91_webhook_url')
                                            ->label('Webhook URL — paste this into MSG91')
                                            ->readOnly()
                                            ->columnSpanFull()
                                            ->helperText('Treat this URL like a password: the token in it is the only thing protecting the endpoint.')
                                            ->suffixAction(
                                                Forms\Components\Actions\Action::make('copy_msg91_webhook_url')
                                                    ->label('Copy')
                                                    ->icon('heroicon-m-clipboard-document')
                                                    ->action(function ($state, $livewire): void {
                                                        // Clipboard write has to happen in the
                                                        // browser; json_encode keeps the value
                                                        // safely quoted inside the JS string.
                                                        $livewire->js('window.navigator.clipboard.writeText(' . json_encode((string) $state) . ');');
                                                        Notification::make()->title('Webhook URL copied')->success()->send();
                                                    })
                                            ),

                                        Forms\Components\Placeholder::make('sms_webhook_protection')
                                            ->label('What protects this endpoint')
                                            ->columnSpanFull()
                                            ->content(new HtmlString(
                                                '<p class="text-sm text-gray-600 dark:text-gray-400">MSG91 offers no signing secret and no custom header on '
                                                . 'delivery reports, so the random token in the URL is the credential. It is unguessable and can be '
                                                . 'rotated, but anyone holding the URL can post to it. The worst a forged report can do is change the '
                                                . 'delivery status shown against a message that was already sent — it cannot send anything, read '
                                                . 'devotee data, or touch payments.</p>'
                                            )),

                                        Forms\Components\Actions::make([
                                            Forms\Components\Actions\Action::make('regenerate_msg91_webhook_token')
                                                ->label('Regenerate token')
                                                ->icon('heroicon-o-arrow-path')
                                                ->color('danger')
                                                ->requiresConfirmation()
                                                ->modalHeading('Regenerate the webhook token?')
                                                ->modalDescription('This immediately invalidates the URL already pasted into MSG91. '
                                                    . 'Delivery reports STOP arriving — and every SMS will show as unconfirmed — until you paste '
                                                    . 'the new URL into the MSG91 dashboard. Only do this if the current URL has leaked.')
                                                ->modalSubmitActionLabel('Yes, regenerate')
                                                ->action(fn () => $this->regenerateMsg91WebhookToken()),
                                        ])->columnSpanFull(),

                                        Forms\Components\Placeholder::make('sms_webhook_recent_events')
                                            ->label('Recent delivery reports')
                                            ->columnSpanFull()
                                            ->content(fn (): HtmlString => $this->renderRecentMsg91Events()),
                                    ]),
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
            'turnstile_' => 'security',
            'mail_' => 'mail',
            'whatsapp_' => 'whatsapp',
            'sms_' => 'sms',
            'app_' => 'app',
        ];

        foreach ($data as $key => $value) {
            // Derived display fields are rendered from other state; storing
            // them would let a stale copy outlive the value it was built from.
            if (in_array($key, self::DISPLAY_ONLY_KEYS, true)) {
                continue;
            }

            $group = 'general';
            foreach ($groups as $prefix => $g) {
                if (str_starts_with($key, $prefix)) {
                    $group = $g;
                    break;
                }
            }

            // Normalise toggles to '1'/'0' so reads can rely on === '1'
            // (a raw false would otherwise persist as an empty string).
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            // FileUpload state can dehydrate as a single-item array —
            // settings values are flat strings (R2 paths).
            if (is_array($value)) {
                $value = (string) (reset($value) ?: '');
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
                "This is a test email from Shree Patadiya Hanumanji Seva Trust Digital Portal.\n\nIf you received this, your SMTP settings are working correctly.",
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

    /**
     * Verify the WhatsApp Cloud API credentials currently entered in
     * the form (works even before the admin clicks "Save"). Surfaces
     * the *actual* Meta error when something is wrong instead of a
     * generic green/red tick.
     */
    public function testWhatsAppConnection(): void
    {
        $this->persistFormState();

        $result = app(WhatsAppService::class)->testConnection();

        if ($result['ok']) {
            Notification::make()
                ->title('WhatsApp connected')
                ->body($result['message'])
                ->success()
                ->send();
            return;
        }

        Notification::make()
            ->title('WhatsApp connection failed')
            ->body($result['message'])
            ->danger()
            ->persistent()
            ->send();
    }

    /**
     * Pull APPROVED templates from the configured WABA and refresh
     * the local cache that powers the template-name dropdown when
     * editing a notification_template row.
     */
    public function syncWhatsAppTemplates(): void
    {
        $this->persistFormState();

        $result = app(WhatsAppService::class)->fetchTemplates();

        if ($result['ok']) {
            Notification::make()
                ->title('WhatsApp templates synced')
                ->body($result['message'])
                ->success()
                ->send();
            return;
        }

        Notification::make()
            ->title('Template sync failed')
            ->body($result['message'])
            ->danger()
            ->persistent()
            ->send();
    }

    /**
     * Persist the form state into temple_system_settings so the
     * service we're about to call sees the same values the admin
     * just typed in. Without this, "Test connection" before "Save"
     * would always test the previously-saved credentials.
     *
     * @param string $prefix Key prefix to scope the flush — typically
     *                       'whatsapp_' or 'sms_'.
     * @param string $group  SystemSetting.group value for new rows.
     */
    private function persistFormState(string $prefix = 'whatsapp_', string $group = 'whatsapp'): void
    {
        $data = $this->form->getState();
        foreach ($data as $key => $value) {
            if (! str_starts_with($key, $prefix)) continue;
            if (in_array($key, self::DISPLAY_ONLY_KEYS, true)) continue;
            SystemSetting::updateOrCreate(
                ['key' => $key],
                ['value' => $value ?? '', 'group' => $group, 'updated_at' => now()]
            );
        }
    }

    /**
     * Verify the MSG91 credentials currently entered in the form by
     * hitting the wallet-balance endpoint. Returns the live balance on
     * success so the admin can confirm both that the auth key works
     * and that the wallet has credit.
     */
    public function testSmsConnection(): void
    {
        $this->persistFormState('sms_', 'sms');

        $result = app(SmsService::class)->testConnection();

        Notification::make()
            ->title($result['ok'] ? 'SMS provider connected' : 'SMS connection failed')
            ->body($result['message'])
            ->color($result['ok'] ? 'success' : 'danger')
            ->persistent(! $result['ok'])
            ->send();
    }

    /**
     * Fire a sample OTP SMS to a number the admin typed into the test
     * recipient field. Uses the configured default OTP template id +
     * a random 6-digit code (NOT persisted to temple_otp_codes — this
     * is a dry-run test, not a real login).
     */
    public function sendTestSms(?string $recipient = null): void
    {
        if (empty($recipient)) {
            Notification::make()->title('Enter a test recipient mobile first.')->warning()->send();
            return;
        }

        $this->persistFormState('sms_', 'sms');

        $sms = app(SmsService::class);
        $templateId = $sms->getOtpTemplateId();

        if ($templateId === '') {
            Notification::make()
                ->title('No default OTP template configured')
                ->body('Paste the MSG91 template id into "Default OTP Template ID" and save before sending a test.')
                ->warning()
                ->send();
            return;
        }

        $code = (string) random_int(100000, 999999);

        // Variable names come from the DLT template, via settings — NOT
        // var1/var2. MSG91 Flow fills by name; the old positional pair
        // matched nothing in the trust's approved template, so every OTP
        // was rejected while this dialog reported success.
        //
        // The validity is whatever OtpService actually enforces, so the
        // SMS can never promise a window the server will not honour.
        $variables = $sms->otpVariables($code);

        $result = $sms->sendTemplate($recipient, $templateId, $variables);

        // Deliberately NOT "Test OTP sent". MSG91 answers 200 success to
        // submissions it later rejects — claiming delivery here is exactly
        // the false reassurance that hid the broken template for weeks.
        Notification::make()
            ->title($result['ok'] ? "Test OTP submitted to MSG91 (code: {$code})" : 'Test SMS failed')
            ->body($result['ok']
                ? 'Sent variables: ' . implode(', ', array_keys($variables))
                    . '. MSG91 accepted the request — that is NOT confirmation of delivery. '
                    . 'Check "Recent delivery reports" below (or the handset) to see what actually happened.'
                : $result['message'])
            ->color($result['ok'] ? 'info' : 'danger')
            ->persistent(! $result['ok'])
            ->send();
    }
}
