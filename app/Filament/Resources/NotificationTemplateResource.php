<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\NotificationTemplateResource\Pages;
use App\Models\NotificationTemplate;
use App\Models\WhatsAppTemplateCache;
use App\Services\Notifications\NotificationRegistry;
use App\Services\Notifications\WhatsAppTemplateBlueprint;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class NotificationTemplateResource extends Resource
{

    protected static ?string $model = NotificationTemplate::class;
    protected static ?string $navigationIcon = 'heroicon-o-envelope-open';
    protected static ?string $navigationGroup = 'Communication';
    protected static ?int $navigationSort = 2;
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
                            ])
                            ->required()
                            ->live()
                            ->columnSpan(1)
                            // The DB has a unique index on (key, channel) —
                            // one template per (trigger × channel) pair.
                            // Validate here so the admin gets a friendly
                            // form-level message instead of a 500 from the
                            // MySQL unique-constraint violation. `ignore()`
                            // is the editing row's id so re-saving the same
                            // record stays valid.
                            ->rules([
                                fn (Forms\Get $get, ?Model $record) =>
                                    Rule::unique('temple_notification_templates', 'channel')
                                        ->where(fn ($q) => $q->where('key', $get('key')))
                                        ->ignore($record?->getKey()),
                            ])
                            ->validationMessages([
                                'unique' => 'A template for this trigger + channel already exists. Edit it instead, or delete it first.',
                            ]),
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
                                return new \Illuminate\Support\HtmlString('<em>Pick a trigger above to see the placeholders this event publishes.</em>');
                            }
                            $info = NotificationRegistry::describe($key);
                            if (! $info) return '';
                            $rows = collect($info['placeholders'])
                                ->map(fn ($desc, $token) => '<li><code>{{ ' . $token . ' }}</code> — ' . e($desc) . '</li>')
                                ->implode('');
                            return new \Illuminate\Support\HtmlString(
                                '<p style="margin-bottom:.5rem">' . e($info['description']) . '</p>'
                                . '<ul style="list-style: disc; padding-left: 1.25rem">' . $rows . '</ul>'
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
            Forms\Components\Section::make('WhatsApp template')
                ->visible(fn (Forms\Get $get) => $get('channel') === NotificationTemplate::CHANNEL_WHATSAPP)
                ->schema([
                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\Select::make('wa_template_name')
                            ->label('Approved template')
                            ->options(fn () => WhatsAppTemplateCache::query()
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(fn ($t) => [
                                    $t->name => $t->name . ' (' . $t->language . ')',
                                ])
                                ->all())
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                // Mirror the template's language code into the
                                // language field so admins don't double-enter it.
                                if (! $state) return;
                                $row = WhatsAppTemplateCache::where('name', $state)->first();
                                if ($row) $set('wa_template_language', $row->language);
                            })
                            ->helperText('Sync templates from Settings → Integrations → WhatsApp before they appear here.'),
                        Forms\Components\TextInput::make('wa_template_language')
                            ->label('Language code')
                            ->default('en')
                            ->required()
                            ->disabled()
                            ->dehydrated()
                            ->helperText('Locked to the template you picked.'),
                    ]),

                    // Auto-detected variables — exactly one input per real
                    // {{n}} the template uses, plus header media and button
                    // URL placeholders. The slot list comes from
                    // WhatsAppTemplateBlueprint::slotsFor() and is recomputed
                    // every time the template selection changes.
                    Forms\Components\Group::make()
                        ->columnSpanFull()
                        ->schema(fn (Forms\Get $get) => self::buildWhatsAppVariableInputs($get)),
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

            // ── Recipient ─────────────────────────────────────────────
            Forms\Components\Section::make('Recipient')->schema([
                Forms\Components\Select::make('recipient_strategy')
                    ->label('Send to')
                    ->options([
                        NotificationTemplate::RECIPIENT_DEVOTEE => 'Devotee in the event (email or phone)',
                        NotificationTemplate::RECIPIENT_TRUST_ADMIN => 'Trust admin (trust_email / trust_phone)',
                        NotificationTemplate::RECIPIENT_ADMIN_USER => 'A specific admin user (pick below)',
                        NotificationTemplate::RECIPIENT_FIXED_EMAIL => 'A specific email address',
                        NotificationTemplate::RECIPIENT_FIXED_PHONE => 'A specific phone number',
                        NotificationTemplate::RECIPIENT_CONTEXT_PATH => 'Look up from the event data (advanced)',
                    ])
                    ->default(NotificationTemplate::RECIPIENT_DEVOTEE)
                    ->required()
                    ->live(),

                // Admin-user picker — visible only when "A specific admin
                // user" is chosen. recipient_value stores the user's id;
                // the resolver looks them up at send time and reads
                // .email or .phone depending on channel. Inactive users
                // are filtered out so admin can't pick someone who's
                // already been offboarded.
                Forms\Components\Select::make('recipient_value')
                    ->label('Admin user')
                    ->options(function () {
                        return \App\Models\AdminUser::query()
                            ->where('is_active', true)
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn ($u) => [
                                $u->id => trim(
                                    $u->name
                                    . ($u->email ? "  ·  {$u->email}" : '')
                                    . ($u->phone ? "  ·  {$u->phone}" : '')
                                ),
                            ])
                            ->all();
                    })
                    ->searchable()
                    ->required()
                    ->helperText('Email channel will use this admin\'s email; WhatsApp / SMS will use their phone. Channel sends are skipped if the matching field is empty on the chosen user.')
                    ->visible(fn (Forms\Get $get) => $get('recipient_strategy') === NotificationTemplate::RECIPIENT_ADMIN_USER),

                // Free-text input — shown for the remaining strategies
                // that need a literal value (fixed email / fixed phone /
                // context dot-path). Devotee + trust-admin + admin-user
                // strategies all resolve from elsewhere, so this stays
                // hidden in those cases.
                Forms\Components\TextInput::make('recipient_value')
                    ->label('Value')
                    ->visible(fn (Forms\Get $get) => in_array($get('recipient_strategy'), [
                        NotificationTemplate::RECIPIENT_FIXED_EMAIL,
                        NotificationTemplate::RECIPIENT_FIXED_PHONE,
                        NotificationTemplate::RECIPIENT_CONTEXT_PATH,
                    ], true))
                    ->helperText('Email address, phone number, or — for the advanced option — a dot-path like booking.contact_email.'),
            ])->columns(2),
        ]);
    }

    /**
     * Build the dynamic auto-detected WhatsApp variable inputs.
     *
     * One Filament TextInput per slot (with a Select-style datalist of
     * available registry tokens). Slot list is derived from the synced
     * Meta template structure so the admin can't miss or duplicate a
     * variable — the form has exactly as many rows as the template has
     * {{n}} placeholders, plus one per media/button URL.
     *
     * The inputs use a `wa_vars` state path so they sit in form state
     * but never get persisted as model attributes — the
     * Create/Edit page hooks serialise them into `wa_components` JSON
     * + `placeholder_map` before save.
     *
     * @return array<int, \Filament\Forms\Components\Component>
     */
    private static function buildWhatsAppVariableInputs(Forms\Get $get): array
    {
        $templateName = $get('wa_template_name');
        $triggerKey = $get('key');

        if (! $templateName) {
            return [
                Forms\Components\Placeholder::make('wa_pick_template_first')
                    ->label('')
                    ->content(new \Illuminate\Support\HtmlString(
                        '<div style="padding:.75rem 1rem; background:#f9fafb; border:1px dashed #d1d5db; border-radius:.5rem; font-size:.875rem;">'
                        . 'Pick an approved template above. The fields you need to fill will appear here automatically — one per <code>{{n}}</code> variable Meta detected.'
                        . '</div>'
                    )),
            ];
        }

        $slots = WhatsAppTemplateBlueprint::slotsFor($templateName);

        if ($slots === []) {
            return [
                Forms\Components\Placeholder::make('wa_no_vars')
                    ->label('')
                    ->content(new \Illuminate\Support\HtmlString(
                        '<div style="padding:.75rem 1rem; background:#ecfdf5; border:1px solid #a7f3d0; border-radius:.5rem; font-size:.875rem;">'
                        . 'This template has no variables — Meta will send the body verbatim. Nothing to fill in.'
                        . '</div>'
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
                    $tokenOptions['{{ ' . $token . ' }}'] = '{{ ' . $token . ' }} — ' . $desc;
                }
            }
        }

        $fields = [
            Forms\Components\Placeholder::make('wa_vars_header')
                ->label('')
                ->content(new \Illuminate\Support\HtmlString(
                    '<div style="font-size:.875rem; color:#4b5563; margin-bottom:.25rem;">'
                    . 'Fill in each variable below. Pick a <code>{{ token }}</code> from the dropdown to insert dynamic data from the event, or type a literal value.'
                    . '</div>'
                )),
        ];

        foreach ($slots as $slot) {
            // Slot keys are underscore-only by design — Filament parses
            // dots in field names into nested state paths, which would
            // scramble our flat lookup. Group statePath keeps these
            // under `wa_vars` without further nesting.
            $stateKey = 'wa_vars.' . $slot['key'];

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
                ? 'Available tokens: ' . implode(', ', array_keys($tokenOptions))
                : '';
            $helper = trim(($slot['help'] ?: '') . ($tokenHint ? "  •  {$tokenHint}" : ''));

            $fields[] = Forms\Components\TextInput::make($stateKey)
                ->label($slot['label'])
                ->placeholder('e.g. {{ donor_name }} or a literal value')
                ->helperText($helper !== '' ? $helper : null)
                ->columnSpanFull();
        }

        return $fields;
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        // Push rows are managed in the separate Push Notifications resource.
        return parent::getEloquentQuery()->whereIn('channel', [
            NotificationTemplate::CHANNEL_EMAIL,
            NotificationTemplate::CHANNEL_WHATSAPP,
            NotificationTemplate::CHANNEL_SMS,
        ]);
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
