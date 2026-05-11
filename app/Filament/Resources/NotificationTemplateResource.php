<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\NotificationTemplateResource\Pages;
use App\Models\NotificationTemplate;
use App\Models\WhatsAppTemplateCache;
use App\Services\Notifications\NotificationRegistry;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class NotificationTemplateResource extends Resource
{
    protected static ?string $model = NotificationTemplate::class;
    protected static ?string $navigationIcon = 'heroicon-o-envelope-open';
    protected static ?string $navigationGroup = 'Communication';
    protected static ?int $navigationSort = 2;
    protected static ?string $navigationLabel = 'Email & WhatsApp';
    protected static ?string $modelLabel = 'Email / WhatsApp template';
    protected static ?string $pluralModelLabel = 'Email & WhatsApp';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Trigger')
                ->description('Pick the domain event this template responds to. Multiple templates can share one trigger so an event can fan out to email, WhatsApp and push at once.')
                ->schema([
                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\Select::make('key')
                            ->label('Trigger')
                            ->options(NotificationRegistry::asOptions())
                            ->searchable()
                            ->required()
                            ->live()
                            // When the admin picks a trigger AND the
                            // placeholder map is still empty, pre-fill it
                            // from the registry so they get every available
                            // token mapped to its dot-path automatically.
                            // Existing maps are never overwritten.
                            ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                                if (! $state) return;
                                $existing = $get('placeholder_map');
                                if (is_array($existing) && count(array_filter($existing)) > 0) return;
                                $info = NotificationRegistry::describe($state);
                                if (! $info) return;
                                $map = [];
                                foreach ($info['placeholders'] as $token => $desc) {
                                    // Description is "Donor name (donation.devotee.name)" —
                                    // pull the dot-path out of the trailing parens.
                                    if (preg_match('/\\(([^)]+)\\)\\s*$/', (string) $desc, $m)) {
                                        $map[$token] = $m[1];
                                    } else {
                                        $map[$token] = $token;
                                    }
                                }
                                $set('placeholder_map', $map);
                            })
                            ->columnSpan(1),
                        Forms\Components\Select::make('channel')
                            ->label('Channel')
                            // Push templates live under the separate
                            // "Push Notifications" resource — they\'re
                            // admin-broadcast, not template-driven.
                            ->options([
                                NotificationTemplate::CHANNEL_EMAIL => 'Email',
                                NotificationTemplate::CHANNEL_WHATSAPP => 'WhatsApp',
                            ])
                            ->required()
                            ->live()
                            ->columnSpan(1),
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
                        ->label('Available placeholders for this trigger')
                        ->columnSpanFull()
                        ->content(function (Forms\Get $get) {
                            $key = $get('key');
                            if (! $key) {
                                return new \Illuminate\Support\HtmlString('<em>Pick a trigger above to see the placeholders available in subject / body / WhatsApp params.</em>');
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

            // ── Email channel ──────────────────────────────────────────
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

            // ── WhatsApp channel ───────────────────────────────────────
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
                            ->helperText('Sync templates from Settings → Integrations → WhatsApp.'),
                        Forms\Components\TextInput::make('wa_template_language')
                            ->label('Language code')
                            ->default('en')
                            ->required()
                            ->helperText('e.g. en, gu_IN, hi_IN.'),
                    ]),

                    Forms\Components\Placeholder::make('wa_components_help')
                        ->label('How to fill the parameter table')
                        ->columnSpanFull()
                        ->content(new \Illuminate\Support\HtmlString(
                            '<div style="font-size:0.875rem;line-height:1.55;">'
                            . '<p style="margin:0 0 0.5rem;">A WhatsApp template message has three possible <strong>sections</strong> — <strong>Header</strong>, <strong>Body</strong> and <strong>Buttons</strong> — each with its own list of variables (the <code>{{1}}</code>, <code>{{2}}</code>… you see in Meta\'s template editor).</p>'
                            . '<ol style="margin:0 0 0.5rem 1.25rem; padding:0;">'
                            . '<li>Add one <strong>outer row</strong> per section your template actually uses (skip sections that have no variables).</li>'
                            . '<li>For each outer row, add one <strong>inner Parameter</strong> per <code>{{n}}</code> placeholder in that section, <em>in the same order</em>.</li>'
                            . '<li>In each inner Parameter, set <em>Type</em> to <code>text</code> (or image/document/video for media) and type a token name into <em>Value token</em> — e.g. <code>donor_name</code>. The Placeholder map below decides what real value the token resolves to (usually it\'s filled for you automatically).</li>'
                            . '</ol>'
                            . '<p style="margin:0;"><strong>Example:</strong> Meta template body says <em>"Hi {{1}}, your donation of ₹{{2}} is received."</em> → one outer row of <code>type: body</code> with two inner Parameters: <code>donor_name</code>, then <code>amount</code>.</p>'
                            . '</div>'
                        )),

                    Forms\Components\Repeater::make('wa_components')
                        ->label('Sections (header / body / button)')
                        ->addActionLabel('+ Add a section')
                        ->itemLabel(fn (array $state): ?string => isset($state['type']) ? strtoupper($state['type']) : null)
                        ->schema([
                            Forms\Components\Grid::make(3)->schema([
                                Forms\Components\Select::make('type')
                                    ->label('Section')
                                    ->options([
                                        'header' => 'Header',
                                        'body' => 'Body',
                                        'button' => 'Button',
                                    ])
                                    ->required(),
                                Forms\Components\Select::make('sub_type')
                                    ->label('Button sub-type')
                                    ->options(['url' => 'URL button', 'quick_reply' => 'Quick reply'])
                                    ->placeholder('— only for buttons —'),
                                Forms\Components\TextInput::make('index')
                                    ->label('Button index')
                                    ->placeholder('0-based, only for buttons')
                                    ->numeric(),
                            ]),

                            Forms\Components\Repeater::make('parameters')
                                ->label('Variables ({{1}}, {{2}}, …) for this section')
                                ->addActionLabel('+ Add a variable')
                                ->itemLabel(fn (array $state): ?string => $state['value_token'] ?? null)
                                ->schema([
                                    Forms\Components\Grid::make(3)->schema([
                                        Forms\Components\Select::make('type')
                                            ->label('Variable type')
                                            ->options([
                                                'text' => 'Text',
                                                'image' => 'Image',
                                                'document' => 'Document',
                                                'video' => 'Video',
                                            ])
                                            ->default('text')
                                            ->required(),
                                        Forms\Components\TextInput::make('value_token')
                                            ->label('Value token')
                                            ->placeholder('e.g. donor_name')
                                            ->helperText('Pick a token from the Placeholder map below.'),
                                        Forms\Components\TextInput::make('filename')
                                            ->label('Filename')
                                            ->placeholder('Only for documents'),
                                    ]),
                                ])
                                ->collapsible(),
                        ])
                        ->collapsible()
                        ->columnSpanFull(),
                ]),

            // Push templates have been moved to the separate "Push
            // Notifications" admin resource — broadcast-style fan-out to
            // every device-token registered with FCM. This resource is
            // now scoped to email + WhatsApp only.

            // ── Recipient + placeholders ───────────────────────────────
            Forms\Components\Section::make('Recipient')->schema([
                Forms\Components\Select::make('recipient_strategy')
                    ->label('Strategy')
                    ->options([
                        NotificationTemplate::RECIPIENT_DEVOTEE => 'Devotee in context (email or phone)',
                        NotificationTemplate::RECIPIENT_TRUST_ADMIN => 'Trust admin (trust_email / trust_phone)',
                        NotificationTemplate::RECIPIENT_FIXED_EMAIL => 'Fixed email address',
                        NotificationTemplate::RECIPIENT_FIXED_PHONE => 'Fixed phone number',
                        NotificationTemplate::RECIPIENT_CONTEXT_PATH => 'Dot-path inside dispatch context',
                    ])
                    ->default(NotificationTemplate::RECIPIENT_DEVOTEE)
                    ->required()
                    ->live(),
                Forms\Components\TextInput::make('recipient_value')
                    ->label('Recipient value')
                    ->visible(fn (Forms\Get $get) => in_array($get('recipient_strategy'), [
                        NotificationTemplate::RECIPIENT_FIXED_EMAIL,
                        NotificationTemplate::RECIPIENT_FIXED_PHONE,
                        NotificationTemplate::RECIPIENT_CONTEXT_PATH,
                    ], true))
                    ->helperText('For fixed_email / fixed_phone enter the address. For context_path enter a dot-path, e.g. booking.contact_email.'),
            ])->columns(2),

            Forms\Components\Section::make('Placeholder map')
                ->description(new \Illuminate\Support\HtmlString(
                    '<div style="font-size:0.875rem;line-height:1.5;">'
                    . '<p style="margin:0 0 0.5rem;"><strong>What is this?</strong> When you write <code>{{ donor_name }}</code> in the subject or body above, this table tells the system where to find the real value for <code>donor_name</code>. <em>You usually do not need to touch this</em> — when you pick a trigger above, this table fills itself automatically with all the available tokens for that trigger.</p>'
                    . '<p style="margin:0;"><strong>If you do edit it:</strong> the <em>Token</em> column is the placeholder you typed (without the curly braces). The <em>Context path</em> column is a dot-separated path into the data the trigger publishes — e.g. <code>donation.devotee.name</code> walks from the donation object, into its devotee relation, and reads the name.</p>'
                    . '</div>'
                ))
                ->collapsible()
                ->collapsed(true)
                ->schema([
                    Forms\Components\KeyValue::make('placeholder_map')
                        ->keyLabel('Token')
                        ->valueLabel('Context path')
                        ->columnSpanFull()
                        ->reorderable(false),
                ]),
        ]);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        // Push rows are managed under the separate Push Notifications
        // resource — keep them out of this list.
        return parent::getEloquentQuery()->whereIn('channel', [
            NotificationTemplate::CHANNEL_EMAIL,
            NotificationTemplate::CHANNEL_WHATSAPP,
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
                    ]),
                Tables\Columns\TextColumn::make('label')->searchable()->limit(40),
                Tables\Columns\IconColumn::make('is_enabled')->boolean()->label('On'),
                Tables\Columns\TextColumn::make('updated_at')->since()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('channel')->options([
                    NotificationTemplate::CHANNEL_EMAIL => 'Email',
                    NotificationTemplate::CHANNEL_WHATSAPP => 'WhatsApp',
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
