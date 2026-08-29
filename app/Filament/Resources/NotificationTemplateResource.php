<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\NotificationTemplateResource\Pages;
use App\Models\AdminUser;
use App\Models\NotificationTemplate;
use App\Models\WhatsAppTemplateCache;
use App\Services\Notifications\NotificationRegistry;
use App\Services\Notifications\WhatsAppTemplateBlueprint;
use Filament\Forms;
use Filament\Forms\Components\Component;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Spatie\Permission\Models\Role;

class NotificationTemplateResource extends Resource
{
    protected static ?string $model = NotificationTemplate::class;

    /**
     * Triggers whose recipients come from a REMINDER RULE, not from this
     * template. The dispatchers (DispatchSevaReminders /
     * DispatchHallReminders) build an in-memory template per delivery and
     * overwrite recipient_strategy + recipient_value from the rule, so
     * anything set here is discarded. The form shows a pointer to the right
     * page instead of a repeater that demands a row and then gets ignored.
     *
     * value = where the admin actually sets them.
     */
    private const RULE_DRIVEN_RECIPIENTS = [
        'seva.booking.reminder' => 'Sevas → edit a seva → Reminders',
        'hall.booking.reminder' => 'Halls → edit a hall → Reminders',
    ];

    protected static ?string $navigationIcon = 'heroicon-o-envelope-open';

    protected static ?string $navigationGroup = 'Communication';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Email · WhatsApp · SMS';

    protected static ?string $modelLabel = 'Email / WhatsApp / SMS template';

    protected static ?string $pluralModelLabel = 'Email · WhatsApp · SMS';

    public static function form(Form $form): Form
    {
        return $form->schema([
            // ── Trigger + channel ──────────────────────────────────────
            Forms\Components\Section::make('Trigger')
                ->description('Pick the event this template responds to. Multiple templates can share a trigger so one event can fan out to email, WhatsApp and SMS at once. If no template exists for a trigger, that channel stays silent — nothing auto-sends.')
                ->schema([
                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\Select::make('key')
                            ->label('Trigger')
                            ->options(NotificationRegistry::asOptions())
                            ->searchable()
                            ->required()
                            ->live()
                            ->columnSpan(1),
                        Forms\Components\Select::make('channel')
                            ->label('Channel')
                            ->options([
                                NotificationTemplate::CHANNEL_EMAIL => 'Email',
                                NotificationTemplate::CHANNEL_WHATSAPP => 'WhatsApp',
                                NotificationTemplate::CHANNEL_SMS => 'SMS',
                                // Trigger pushes became admin-composable
                                // 2026-08-29. Before that the push channel
                                // existed in the driver and in reminder rules
                                // but had no form, so nobody could write one.
                                NotificationTemplate::CHANNEL_PUSH => 'Push notification (app)',
                            ])
                            ->required()
                            ->live()
                            ->columnSpan(1),
                        // Multiple templates per (trigger × channel) are
                        // intentionally allowed — eg seva.booking.reminder
                        // PUSH to the devotee AND a separate PUSH to an
                        // admin role with different body + recipient
                        // strategy. NotificationService iterates every
                        // enabled row and fans out independently. The
                        // matching DB unique index was dropped in
                        // 2026_05_16_120000.
                    ]),

                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\TextInput::make('label')
                            ->label('Internal label')
                            ->required()
                            ->columnSpan(1),
                        Forms\Components\Toggle::make('is_enabled')
                            ->label('Enabled')
                            ->default(true)
                            ->inline(false)
                            ->columnSpan(1),
                    ]),

                    Forms\Components\Placeholder::make('placeholders_help')
                        ->label('Available placeholders')
                        ->columnSpanFull()
                        ->content(function (Forms\Get $get) {
                            $key = $get('key');
                            if (! $key) {
                                return new HtmlString('<em>Pick a trigger above to see the placeholders this event publishes.</em>');
                            }
                            $info = NotificationRegistry::describe($key);
                            if (! $info) {
                                return '';
                            }
                            $rows = collect($info['placeholders'])
                                ->map(fn ($desc, $token) => '<li><code>{{ '.$token.' }}</code> — '.e($desc).'</li>')
                                ->implode('');

                            return new HtmlString(
                                '<p style="margin-bottom:.5rem">'.e($info['description']).'</p>'
                                .'<ul style="list-style: disc; padding-left: 1.25rem">'.$rows.'</ul>'
                            );
                        }),

                    Forms\Components\Textarea::make('description')
                        ->label('Internal notes (optional)')
                        ->rows(2)
                        ->columnSpanFull(),
                ])->columns(1),

            // ── Email ─────────────────────────────────────────────────
            Forms\Components\Section::make('Email content')
                ->visible(fn (Forms\Get $get) => $get('channel') === NotificationTemplate::CHANNEL_EMAIL)
                ->schema([
                    Forms\Components\TextInput::make('subject')
                        ->label('Subject')
                        ->required()
                        ->maxLength(255)
                        ->helperText('Supports {{ placeholder }} substitutions.')
                        ->columnSpanFull(),
                    Forms\Components\Textarea::make('body')
                        ->label('HTML body')
                        ->rows(12)
                        ->required()
                        ->helperText('HTML email body. Use {{ placeholder }} substitutions.')
                        ->columnSpanFull(),
                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\TextInput::make('from_name')
                            ->label('From name (override)')
                            ->placeholder('Falls back to mail_from_name'),
                        Forms\Components\TextInput::make('from_address')
                            ->label('From address (override)')
                            ->email()
                            ->placeholder('Falls back to mail_from_address'),
                    ]),
                ]),

            // ── WhatsApp ──────────────────────────────────────────────
            //
            // One approved Meta template per language (gu / hi / en). The
            // devotee's saved language preference picks the variant at
            // send time; Gujarati is the fallback when a language tab is
            // left empty. Stored in the `wa_variants` JSON column; the
            // legacy wa_template_name/-language/-components columns are
            // kept mirrored to the Gujarati (or first) variant.
            Forms\Components\Section::make('WhatsApp templates — per language')
                ->description('Pick the approved template for each language. Devotees receive the variant matching their app/website language; Gujarati is sent when their language is not configured here.')
                ->visible(fn (Forms\Get $get) => $get('channel') === NotificationTemplate::CHANNEL_WHATSAPP)
                ->schema([
                    Forms\Components\Tabs::make('wa_language_variants')
                        ->columnSpanFull()
                        ->tabs([
                            self::waVariantTab('gu', 'ગુજરાતી (Gujarati)'),
                            self::waVariantTab('hi', 'हिन्दी (Hindi)'),
                            self::waVariantTab('en', 'English'),
                        ]),
                ]),

            // ── SMS ───────────────────────────────────────────────────
            Forms\Components\Section::make('SMS template')
                ->visible(fn (Forms\Get $get) => $get('channel') === NotificationTemplate::CHANNEL_SMS)
                ->schema([
                    Forms\Components\TextInput::make('sms_template_id')
                        ->label('MSG91 Template ID')
                        ->placeholder('65a1b2c3d4e5f6...')
                        ->helperText('Paste the DLT-approved template ID from MSG91. Leave blank to use the default OTP template id from System Settings → SMS. Variables are filled in {{n}} order from the placeholders available above.')
                        ->columnSpanFull(),
                ]),

            // ── Push ──────────────────────────────────────────────────
            Forms\Components\Section::make('Push notification')
                ->visible(fn (Forms\Get $get) => $get('channel') === NotificationTemplate::CHANNEL_PUSH)
                ->description('Only devotees with the app installed AND a registered device receive these. A devotee who has never opened the app is silently skipped — use WhatsApp for them.')
                ->schema([
                    Forms\Components\Tabs::make('push_content')
                        ->columnSpanFull()
                        ->tabs(collect([
                            'gu' => 'ગુજરાતી',
                            'hi' => 'हिन्दी',
                            'en' => 'English',
                        ])->map(fn (string $label, string $locale) => Forms\Components\Tabs\Tab::make($label)->schema([
                            Forms\Components\TextInput::make("push_title.{$locale}")
                                ->label('Title')
                                ->maxLength(65)
                                // Android truncates around 65 chars in the
                                // shade; anything past it is never read.
                                ->helperText($locale === 'gu'
                                    ? 'Required — the other two languages fall back to this one.'
                                    : 'Optional. Falls back to Gujarati.')
                                ->required($locale === 'gu'),
                            Forms\Components\Textarea::make("push_body.{$locale}")
                                ->label('Message')
                                ->rows(3)
                                ->maxLength(240),
                        ]))->values()->all()),
                ]),

            // Same intent vocabulary as a broadcast push, and the same
            // Flutter DeepLinkRouter handles both. The picker is borrowed
            // from NotificationResource rather than re-listed here, so a
            // screen added for broadcasts is offered here too.
            Forms\Components\Section::make('Open in app on tap')
                ->visible(fn (Forms\Get $get) => $get('channel') === NotificationTemplate::CHANNEL_PUSH)
                ->description('Where the devotee lands when they tap it. Leave blank to just open the app.')
                ->schema([
                    Forms\Components\Select::make('push_intent')
                        ->label('Open')
                        ->options(NotificationResource::intentOptions())
                        ->placeholder('— Just open the app (home) —')
                        ->live()
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('push_intent_target', null))
                        ->columnSpanFull(),

                    // Synthetic: the persisted shape is the intent_params
                    // JSON, built from this on save. Same pattern the
                    // broadcast form uses.
                    Forms\Components\Select::make('push_intent_target')
                        ->label('Target')
                        ->options(fn (Forms\Get $get): array => NotificationResource::intentTargetOptions((string) $get('push_intent')))
                        ->searchable()
                        ->preload()
                        // NOT dehydrated(false): the value has to reach
                        // $data so the save-time serialiser can turn it into
                        // push_intent_params. `push_intent_target` is not
                        // fillable on the model, so the stray key is inert
                        // even though it is carried through.
                        ->required(fn (Forms\Get $get): bool => array_key_exists(
                            (string) $get('push_intent'),
                            NotificationResource::intentTargetKeys(),
                        ))
                        ->visible(fn (Forms\Get $get): bool => array_key_exists(
                            (string) $get('push_intent'),
                            NotificationResource::intentTargetKeys(),
                        )),
                ]),

            // ── Recipients ────────────────────────────────────────────
            //
            // One template, many recipients. The repeater persists into
            // the `recipients` JSON column; each entry independently
            // resolves at dispatch time (devotee + admin role + fixed
            // phone all firing off the same body in one go).
            //
            // PUSH templates are kept single-recipient (devotee) — admin
            // users don't register FCM tokens, so an admin-role push
            // entry would silently no-op. The whole repeater is hidden
            // on the push channel; NotificationService falls back to
            // the legacy single-recipient logic via the
            // recipient_strategy / recipient_value columns for push.
            // Reminder triggers take their recipients from the rule (see
            // RULE_DRIVEN_RECIPIENTS above), so show a pointer rather than a
            // repeater. Hall reminders shipped after the seva ones and were
            // missed here, which left the form demanding at least one
            // recipient row for a value it then threw away.
            Forms\Components\Section::make('Recipients')
                ->visible(fn (Forms\Get $get) => array_key_exists((string) $get('key'), self::RULE_DRIVEN_RECIPIENTS))
                ->schema([
                    Forms\Components\Placeholder::make('reminder_recipients_note')
                        ->hiddenLabel()
                        ->content(fn (Forms\Get $get) => new HtmlString(
                            'Recipients for this reminder are set per-rule under <strong>'
                            .e(self::RULE_DRIVEN_RECIPIENTS[(string) $get('key')] ?? '')
                            .'</strong> ("Who receives it"). This template only supplies the wording &amp; variables.'
                        )),
                ]),

            Forms\Components\Section::make('Recipients')
                ->description('Each row resolves independently — add more to send the same message to multiple recipients in one go. Staff rows (trust admin, admin user, role, seva assignee) always send in Gujarati; devotees get their own language.')
                ->visible(fn (Forms\Get $get) => $get('channel') !== NotificationTemplate::CHANNEL_PUSH
                    && ! array_key_exists((string) $get('key'), self::RULE_DRIVEN_RECIPIENTS))
                ->schema([
                    Forms\Components\Repeater::make('recipients')
                        ->hiddenLabel()
                        ->defaultItems(1)
                        ->minItems(1)
                        ->addActionLabel('Add another recipient')
                        ->reorderable(false)
                        ->grid(1)
                        ->itemLabel(function (array $state): ?string {
                            $strategy = $state['strategy'] ?? null;

                            return match ($strategy) {
                                NotificationTemplate::RECIPIENT_DEVOTEE => 'Devotee in the event',
                                NotificationTemplate::RECIPIENT_TRUST_ADMIN => 'Trust admin',
                                NotificationTemplate::RECIPIENT_ADMIN_USER => 'Specific admin user',
                                NotificationTemplate::RECIPIENT_ADMIN_ROLE => 'Admin role: '.($state['value'] ?: '—'),
                                NotificationTemplate::RECIPIENT_SEVA_ASSIGNEE => 'Seva assignee',
                                NotificationTemplate::RECIPIENT_FIXED_EMAIL => 'Fixed email: '.($state['value'] ?: '—'),
                                NotificationTemplate::RECIPIENT_FIXED_PHONE => 'Fixed phone: '.($state['value'] ?: '—'),
                                NotificationTemplate::RECIPIENT_CONTEXT_PATH => 'Context path: '.($state['value'] ?: '—'),
                                default => 'Recipient',
                            };
                        })
                        ->schema([
                            Forms\Components\Select::make('strategy')
                                ->label('Send to')
                                // The seva-assignee option only makes sense on
                                // seva.* triggers — their context is the one
                                // carrying the booking's seva. Kept visible when
                                // a row is already set to it so an existing
                                // recipient never renders as a blank select.
                                ->options(fn (Forms\Get $get): array => array_filter([
                                    NotificationTemplate::RECIPIENT_DEVOTEE => 'Devotee in the event (email or phone)',
                                    NotificationTemplate::RECIPIENT_SEVA_ASSIGNEE => self::offersSevaAssignee($get)
                                        ? 'Seva assignee (the staff member assigned to this seva)'
                                        : null,
                                    NotificationTemplate::RECIPIENT_TRUST_ADMIN => 'Trust admin (trust_email / trust_phone)',
                                    NotificationTemplate::RECIPIENT_ADMIN_USER => 'A specific admin user',
                                    NotificationTemplate::RECIPIENT_ADMIN_ROLE => 'Every admin holding a role',
                                    NotificationTemplate::RECIPIENT_FIXED_EMAIL => 'A specific email address',
                                    NotificationTemplate::RECIPIENT_FIXED_PHONE => 'A specific phone number',
                                    NotificationTemplate::RECIPIENT_CONTEXT_PATH => 'Look up from the event data (advanced)',
                                ]))
                                ->default(NotificationTemplate::RECIPIENT_DEVOTEE)
                                ->required()
                                ->live(),

                            // seva_assignee needs no value — the address is
                            // looked up per booking at send time. Say so, or
                            // the row reads as an unfinished form.
                            Forms\Components\Placeholder::make('seva_assignee_note')
                                ->hiddenLabel()
                                ->content(new HtmlString(
                                    'Goes to the admin set as <strong>Seva Assignee</strong> on the booked seva '
                                    .'(Sevas &rarr; edit a seva). Email uses their admin email, WhatsApp / SMS their phone. '
                                    .'Nothing to fill in here — each booking resolves to its own assignee. '
                                    .'Use <code>{{ admin.name }}</code> in the message to name them. Sends in Gujarati.'
                                ))
                                ->visible(fn (Forms\Get $get) => $get('strategy') === NotificationTemplate::RECIPIENT_SEVA_ASSIGNEE),

                            // Admin-user picker (only when strategy = admin_user)
                            Forms\Components\Select::make('value')
                                ->label('Admin user')
                                ->options(function () {
                                    return AdminUser::query()
                                        ->where('is_active', true)
                                        ->orderBy('name')
                                        ->get()
                                        ->mapWithKeys(fn ($u) => [
                                            $u->id => trim(
                                                $u->name
                                                .($u->email ? "  ·  {$u->email}" : '')
                                                .($u->phone ? "  ·  {$u->phone}" : '')
                                            ),
                                        ])
                                        ->all();
                                })
                                ->searchable()
                                ->required()
                                ->visible(fn (Forms\Get $get) => $get('strategy') === NotificationTemplate::RECIPIENT_ADMIN_USER),

                            // Role picker (only when strategy = admin_role)
                            Forms\Components\Select::make('value')
                                ->label('Admin role')
                                ->options(function () {
                                    return Role::query()
                                        ->where('guard_name', 'admin')
                                        ->orderBy('name')
                                        ->pluck('name', 'name')
                                        ->all();
                                })
                                ->searchable()
                                ->required()
                                ->helperText('Every active admin with this role gets the notification.')
                                ->visible(fn (Forms\Get $get) => $get('strategy') === NotificationTemplate::RECIPIENT_ADMIN_ROLE),

                            // Free-text input (fixed email / fixed phone / context path)
                            Forms\Components\TextInput::make('value')
                                ->label('Value')
                                ->helperText('Email address, phone number, or — for the advanced option — a dot-path like booking.contact_phone.')
                                ->visible(fn (Forms\Get $get) => in_array($get('strategy'), [
                                    NotificationTemplate::RECIPIENT_FIXED_EMAIL,
                                    NotificationTemplate::RECIPIENT_FIXED_PHONE,
                                    NotificationTemplate::RECIPIENT_CONTEXT_PATH,
                                ], true)),
                        ]),
                ]),

            // Push templates stay single-recipient (devotee) — admin
            // users have no FCM tokens, so any admin-role entry would
            // silently no-op. Keep the legacy single dropdown for push.
            Forms\Components\Section::make('Recipient')
                ->visible(fn (Forms\Get $get) => $get('channel') === NotificationTemplate::CHANNEL_PUSH
                    && $get('key') !== 'seva.booking.reminder')
                ->schema([
                    Forms\Components\Select::make('recipient_strategy')
                        ->label('Send to')
                        ->options([
                            NotificationTemplate::RECIPIENT_DEVOTEE => 'Devotee in the event',
                        ])
                        ->default(NotificationTemplate::RECIPIENT_DEVOTEE)
                        ->disabled()
                        ->dehydrated()
                        ->helperText('Push notifications only support the in-event devotee — admin users do not register FCM tokens. Use WhatsApp / SMS / Email for admin recipients.'),
                ]),
        ]);
    }

    /**
     * Whether this recipient row may pick "Seva assignee".
     *
     * Only seva.* triggers dispatch a context carrying the booked seva,
     * so the option is hidden everywhere else — but a row already set to
     * it keeps the option so an existing recipient never renders blank.
     * `../../key` walks out of the repeater item to the form's trigger
     * select.
     */
    private static function offersSevaAssignee(Forms\Get $get): bool
    {
        if ($get('strategy') === NotificationTemplate::RECIPIENT_SEVA_ASSIGNEE) {
            return true;
        }

        return str_starts_with((string) $get('../../key'), 'seva.');
    }

    /**
     * One language tab of the WhatsApp section: an approved-template
     * Select filtered to that language's cache rows, plus the
     * auto-detected variable inputs for the picked template.
     *
     * Select option values are "{name}|{language}" composites — the
     * cache is unique on (name, language), so name alone is ambiguous
     * (a Meta template family has one row per translation).
     */
    private static function waVariantTab(string $locale, string $label): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make($label)
            ->badge(fn (Forms\Get $get) => $get("wa_variant_pick.{$locale}") ? '✓' : null)
            ->schema([
                Forms\Components\Select::make("wa_variant_pick.{$locale}")
                    ->label('Approved template')
                    ->options(fn () => WhatsAppTemplateCache::query()
                        ->where('language', 'like', $locale.'%')
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn ($t) => [
                            $t->name.'|'.$t->language => $t->name.' ('.$t->language.')',
                        ])
                        ->all())
                    ->searchable()
                    ->live()
                    ->helperText($locale === 'gu'
                        ? 'Sent by default and whenever a devotee\'s language has no template. Sync templates from Settings → Integrations → WhatsApp before they appear here.'
                        : 'Optional — leave empty to fall back to the Gujarati template for these devotees.'),
                Forms\Components\Group::make()
                    ->columnSpanFull()
                    ->schema(fn (Forms\Get $get) => self::buildWhatsAppVariableInputs($get, $locale)),
            ]);
    }

    /**
     * Build the dynamic auto-detected WhatsApp variable inputs for one
     * language variant.
     *
     * One Filament TextInput per slot (with a Select-style datalist of
     * available registry tokens). Slot list is derived from the synced
     * Meta template structure so the admin can't miss or duplicate a
     * variable — the form has exactly as many rows as the template has
     * {{n}} placeholders, plus one per media/button URL.
     *
     * The inputs use a per-locale `wa_vars_{locale}` state path so they
     * sit in form state but never get persisted as model attributes —
     * the Create/Edit page hooks serialise them into the `wa_variants`
     * JSON + `placeholder_map` before save.
     *
     * @return array<int, Component>
     */
    private static function buildWhatsAppVariableInputs(Forms\Get $get, string $locale): array
    {
        $composite = $get("wa_variant_pick.{$locale}");
        $triggerKey = $get('key');

        if (! $composite) {
            return [
                Forms\Components\Placeholder::make('wa_pick_template_first_'.$locale)
                    ->label('')
                    ->content(new HtmlString(
                        '<div style="padding:.75rem 1rem; background:#f9fafb; border:1px dashed #d1d5db; border-radius:.5rem; font-size:.875rem;">'
                        .'Pick an approved template above. The fields you need to fill will appear here automatically — one per <code>{{n}}</code> variable Meta detected.'
                        .'</div>'
                    )),
            ];
        }

        [$templateName, $templateLanguage] = array_pad(explode('|', (string) $composite, 2), 2, null);

        $slots = WhatsAppTemplateBlueprint::slotsFor((string) $templateName, $templateLanguage ?: null);

        if ($slots === []) {
            return [
                Forms\Components\Placeholder::make('wa_no_vars_'.$locale)
                    ->label('')
                    ->content(new HtmlString(
                        '<div style="padding:.75rem 1rem; background:#ecfdf5; border:1px solid #a7f3d0; border-radius:.5rem; font-size:.875rem;">'
                        .'This template has no variables — Meta will send the body verbatim. Nothing to fill in.'
                        .'</div>'
                    )),
            ];
        }

        // Registry tokens available for THIS trigger — admin can pick
        // any of them or type a literal value.
        $tokenOptions = [];
        if ($triggerKey) {
            $info = NotificationRegistry::describe($triggerKey);
            if ($info) {
                foreach ($info['placeholders'] as $token => $desc) {
                    $tokenOptions['{{ '.$token.' }}'] = '{{ '.$token.' }} — '.$desc;
                }
            }
        }

        $fields = [
            Forms\Components\Placeholder::make('wa_vars_header_'.$locale)
                ->label('')
                ->content(new HtmlString(
                    '<div style="font-size:.875rem; color:#4b5563; margin-bottom:.25rem;">'
                    .'Fill in each variable below. Pick a <code>{{ token }}</code> from the dropdown to insert dynamic data from the event, or type a literal value.'
                    .'</div>'
                )),
        ];

        foreach ($slots as $slot) {
            // Slot keys are underscore-only by design — Filament parses
            // dots in field names into nested state paths, which would
            // scramble our flat lookup. The per-locale prefix keeps each
            // language's values in its own bag without further nesting.
            $stateKey = 'wa_vars_'.$locale.'.'.$slot['key'];

            // Both inputs MUST be dehydrated (default true). Earlier
            // versions used dehydrated(false) which silently stripped
            // wa_vars from the save payload — mappings never persisted.
            // mutateFormDataBeforeSave (Edit/Create page) consumes the
            // wa_vars bag and converts it to wa_components JSON, then
            // unsets wa_vars before save reaches the model.
            if ($slot['is_filename']) {
                $fields[] = Forms\Components\TextInput::make($stateKey)
                    ->label($slot['label'])
                    ->placeholder('e.g. 80G_Receipt.pdf')
                    ->helperText($slot['help'] ?: null)
                    ->columnSpanFull();

                continue;
            }

            // Plain TextInput with a helperText listing the available
            // tokens. A Select looked cleaner but constrained values to
            // its options list — admins couldn't type a literal string
            // for things like filenames or static URLs. Free-form
            // input + the placeholders panel at the top of the form
            // gives the same UX without the constraint.
            $tokenHint = $tokenOptions
                ? 'Available tokens: '.implode(', ', array_keys($tokenOptions))
                : '';
            $helper = trim(($slot['help'] ?: '').($tokenHint ? "  •  {$tokenHint}" : ''));

            $fields[] = Forms\Components\TextInput::make($stateKey)
                ->label($slot['label'])
                ->placeholder('e.g. {{ donor_name }} or a literal value')
                ->helperText($helper !== '' ? $helper : null)
                ->columnSpanFull();
        }

        return $fields;
    }

    /**
     * Turn the admin's chosen target into the intent_params JSON the app
     * reads. Mirrors the broadcast form's conversion; kept here as a static
     * so the field closure and the Edit page's hydration share one rule.
     *
     * @return array<string, mixed>|null
     */
    public static function intentParamsFor(string $intent, mixed $target): ?array
    {
        $key = NotificationResource::intentTargetKeys()[$intent] ?? null;

        if ($key === null || blank($target)) {
            return null;
        }

        return [$key => (string) $target];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('key')->label('Trigger')->searchable()->sortable(),
                Tables\Columns\BadgeColumn::make('channel')
                    ->colors([
                        'primary' => NotificationTemplate::CHANNEL_EMAIL,
                        'success' => NotificationTemplate::CHANNEL_WHATSAPP,
                        'warning' => NotificationTemplate::CHANNEL_SMS,
                    ]),
                Tables\Columns\TextColumn::make('label')->searchable()->limit(40),
                Tables\Columns\IconColumn::make('is_enabled')->boolean()->label('On'),
                Tables\Columns\TextColumn::make('updated_at')->since()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('channel')->options([
                    NotificationTemplate::CHANNEL_EMAIL => 'Email',
                    NotificationTemplate::CHANNEL_WHATSAPP => 'WhatsApp',
                    NotificationTemplate::CHANNEL_SMS => 'SMS',
                ]),
                Tables\Filters\TernaryFilter::make('is_enabled')->label('Enabled'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('key');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotificationTemplates::route('/'),
            'create' => Pages\CreateNotificationTemplate::route('/create'),
            'edit' => Pages\EditNotificationTemplate::route('/{record}/edit'),
        ];
    }
}
