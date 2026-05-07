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
    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';
    protected static ?string $navigationGroup = 'System';
    protected static ?int $navigationSort = 2;
    protected static ?string $navigationLabel = 'Notifications';
    protected static ?string $modelLabel = 'Notification template';
    protected static ?string $pluralModelLabel = 'Notification templates';

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
                            ->columnSpan(1),
                        Forms\Components\Select::make('channel')
                            ->label('Channel')
                            ->options([
                                NotificationTemplate::CHANNEL_EMAIL => 'Email',
                                NotificationTemplate::CHANNEL_WHATSAPP => 'WhatsApp',
                                NotificationTemplate::CHANNEL_PUSH => 'Push',
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

                    Forms\Components\Repeater::make('wa_components')
                        ->label('Template parameters')
                        ->helperText('Mirror the parameters of the chosen WhatsApp template, in order. Each value_token is resolved through the placeholder map below.')
                        ->schema([
                            Forms\Components\Grid::make(3)->schema([
                                Forms\Components\Select::make('type')->options([
                                    'header' => 'Header',
                                    'body' => 'Body',
                                    'button' => 'Button',
                                ])->required(),
                                Forms\Components\TextInput::make('sub_type')->placeholder('url / quick_reply (button only)'),
                                Forms\Components\TextInput::make('index')->placeholder('Button index (0-based)')->numeric(),
                            ]),
                            Forms\Components\Repeater::make('parameters')
                                ->schema([
                                    Forms\Components\Grid::make(3)->schema([
                                        Forms\Components\Select::make('type')->options([
                                            'text' => 'Text',
                                            'image' => 'Image',
                                            'document' => 'Document',
                                            'video' => 'Video',
                                        ])->default('text')->required(),
                                        Forms\Components\TextInput::make('value_token')
                                            ->placeholder('e.g. donor_name')
                                            ->helperText('Token resolved via placeholder map.'),
                                        Forms\Components\TextInput::make('filename')
                                            ->placeholder('Document filename (optional)'),
                                    ]),
                                ])->collapsible()->itemLabel(fn (array $state): ?string => $state['value_token'] ?? null),
                        ])
                        ->collapsible()
                        ->columnSpanFull(),
                ]),

            // ── Push channel ───────────────────────────────────────────
            Forms\Components\Section::make('Push notification content')
                ->visible(fn (Forms\Get $get) => $get('channel') === NotificationTemplate::CHANNEL_PUSH)
                ->schema([
                    Forms\Components\Tabs::make('Push')->tabs([
                        Forms\Components\Tabs\Tab::make('ગુજરાતી')->schema([
                            Forms\Components\TextInput::make('push_title.gu')->label('Title (GU)')->maxLength(64),
                            Forms\Components\Textarea::make('push_body.gu')->label('Body (GU)')->rows(3),
                        ]),
                        Forms\Components\Tabs\Tab::make('हिन्दी')->schema([
                            Forms\Components\TextInput::make('push_title.hi')->label('Title (HI)')->maxLength(64),
                            Forms\Components\Textarea::make('push_body.hi')->label('Body (HI)')->rows(3),
                        ]),
                        Forms\Components\Tabs\Tab::make('English')->schema([
                            Forms\Components\TextInput::make('push_title.en')->label('Title (EN)')->maxLength(64),
                            Forms\Components\Textarea::make('push_body.en')->label('Body (EN)')->rows(3),
                        ]),
                    ])->columnSpanFull(),
                    Forms\Components\TextInput::make('push_deep_link')
                        ->label('Deep link (optional)')
                        ->placeholder('app://donations/{{ donation_id }}')
                        ->columnSpanFull(),
                ]),

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
                ->description('Map each {{ token }} you used above to a dot-path inside the dispatch context. Tokens with no entry here resolve against the context as-is.')
                ->schema([
                    Forms\Components\KeyValue::make('placeholder_map')
                        ->keyLabel('Token')
                        ->valueLabel('Context path')
                        ->columnSpanFull()
                        ->reorderable(false),
                ]),
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
                        'warning' => NotificationTemplate::CHANNEL_PUSH,
                    ]),
                Tables\Columns\TextColumn::make('label')->searchable()->limit(40),
                Tables\Columns\IconColumn::make('is_enabled')->boolean()->label('On'),
                Tables\Columns\TextColumn::make('updated_at')->since()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('channel')->options([
                    NotificationTemplate::CHANNEL_EMAIL => 'Email',
                    NotificationTemplate::CHANNEL_WHATSAPP => 'WhatsApp',
                    NotificationTemplate::CHANNEL_PUSH => 'Push',
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
