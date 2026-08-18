<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SevaResource\Pages;
use App\Filament\Support\ReminderRuleFields;
use App\Filament\Support\TranslatableTabs;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Seva;
use App\Services\SevaReminderScheduler;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SevaResource extends Resource
{
    protected static ?string $model = Seva::class;

    protected static ?string $navigationIcon = 'heroicon-o-hand-raised';

    protected static ?string $navigationGroup = 'Portal Setup';

    protected static ?int $navigationSort = 30;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Content')->schema([
                TranslatableTabs::make(fn (string $locale, string $label) => [
                    Forms\Components\TextInput::make("name_{$locale}")->label("Name {$label}")->required()->maxLength(255),
                    Forms\Components\RichEditor::make("description_{$locale}")->label("Description {$label}"),
                ]),
            ]),

            Forms\Components\Section::make('Pricing & Config')->schema([
                Forms\Components\Select::make('assignee_id')
                    ->label('Seva Assignee')
                    ->relationship('assignee', 'name', fn ($query) => $query->where('is_active', true))
                    ->required()
                    ->searchable()
                    ->preload()
                    ->helperText('Admin user responsible for this seva.')
                    ->createOptionForm([
                        Forms\Components\TextInput::make('name')->required()->maxLength(255),
                        Forms\Components\TextInput::make('email')->email()->required()->unique('temple_admin_users', 'email')->maxLength(255),
                        Forms\Components\TextInput::make('password')->password()->required()->maxLength(255),
                        Forms\Components\TextInput::make('phone')->tel()->maxLength(15),
                        Forms\Components\Toggle::make('is_active')->default(true),
                    ]),
                Forms\Components\Select::make('category')
                    ->options(fn (): array => \App\Models\SevaCategory::orderBy('sort_order')
                        ->get()
                        ->mapWithKeys(fn ($c) => [$c->slug => $c->name_en ?? $c->name_gu])
                        ->all())
                    ->required()
                    ->helperText('Manage the list via the "Categories" button on the Sevas page.'),
                Forms\Components\TextInput::make('price')->numeric()->prefix('₹')->required(),
                Forms\Components\TextInput::make('min_price')->numeric()->prefix('₹'),
                Forms\Components\Toggle::make('is_variable_price')->label('Variable Price'),
                Forms\Components\Toggle::make('requires_booking')->label('Requires Booking')->default(true)->live(),
                Forms\Components\TextInput::make('sort_order')->numeric()->default(0),
                Forms\Components\Toggle::make('is_active')->label('Active')->default(true),
            ])->columns(2),

            Forms\Components\Section::make('Image')->schema([
                Forms\Components\FileUpload::make('image_path')->image()->directory('sevas')->maxSize(2048),
            ]),

            Forms\Components\Section::make('Photos & Videos')
                ->description('Gallery of photos and videos for this seva. Photos are uploaded; videos are YouTube / hosted links.')
                ->schema([
                    Forms\Components\Repeater::make('media')
                        ->relationship()
                        ->schema([
                            Forms\Components\Select::make('media_type')
                                ->options(['photo' => 'Photo', 'video' => 'Video'])
                                ->default('photo')
                                ->live()
                                ->required(),
                            Forms\Components\FileUpload::make('image_path')
                                ->label('Photo')
                                ->image()
                                ->directory('seva-media')
                                ->maxSize(4096)
                                ->visible(fn (Get $get): bool => $get('media_type') === 'photo'),
                            Forms\Components\TextInput::make('video_url')
                                ->label('Video URL')
                                ->url()
                                ->maxLength(500)
                                ->placeholder('https://youtu.be/xxxxxxxxxxx')
                                ->visible(fn (Get $get): bool => $get('media_type') === 'video'),
                            Forms\Components\TextInput::make('sort_order')->numeric()->default(0),
                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->reorderable()
                        ->orderColumn('sort_order')
                        ->addActionLabel('Add Photo / Video'),
                ]),

            Forms\Components\Section::make('Slot Configuration')
                ->icon('heroicon-o-clock')
                ->visible(fn (Get $get) => (bool) $get('requires_booking'))
                ->schema([
                    Forms\Components\Select::make('slot_pool_id')
                        ->label('Shared Slot Pool')
                        ->relationship('slotPool', 'name')
                        ->nullable()
                        ->searchable()
                        ->preload()
                        ->live()
                        ->helperText('Sevas in the same pool share ONE set of slots — booking any of them fills the same capacity for all. Slot settings below come from the pool (manage pools under Portal Setup → Seva Slot Pools). Leave empty for independent slots.'),

                    // Own slot settings apply only when the seva is NOT in
                    // a pool; pooled sevas follow the pool's configuration.
                    Forms\Components\Group::make(\App\Filament\Support\SlotConfigFields::schema())
                        ->visible(fn (Get $get) => blank($get('slot_pool_id'))),
                ])->collapsible(),

            Forms\Components\Section::make('Reminders')
                ->icon('heroicon-o-bell')
                ->description('Add any number of rules — each one is when + who + channel + message. Applies to bookings confirmed after the rule exists.')
                ->collapsed()
                ->schema([
                    Forms\Components\Toggle::make('send_darshan_on_booking_date')
                        ->label('Send daily darshan photo to booked devotees')
                        ->helperText('When the day\'s first Daily Darshan photo is uploaded, every devotee with a confirmed booking of this seva for that date receives it — via the templates configured on the "Darshan — photo for booking-day devotees" trigger.'),
                    Forms\Components\Repeater::make('reminderRules')
                        ->relationship()
                        // G7 (2026-08-09): `seva::reminder::rule` had 12
                        // seeded permissions and a SevaReminderRulePolicy but
                        // NO resource — nothing ever consulted them, while
                        // the real edit path (this repeater) rode in on
                        // update_seva alone. This is the check that makes the
                        // permission real. visible() rather than disabled():
                        // Filament skips hidden components entirely when
                        // dehydrating AND when running saveRelationships, so
                        // a user without the permission cannot add, edit or
                        // silently wipe existing rules.
                        ->visible(fn (): bool => auth('admin')->user()?->can('update_seva::reminder::rule') ?? false)
                        ->hiddenLabel()
                        ->schema(ReminderRuleFields::schema())
                        ->columns(2)
                        ->defaultItems(0)
                        ->addActionLabel('Add reminder rule')
                        ->collapsible()
                        ->itemLabel(fn (array $state): ?string => isset($state['offset_minutes'])
                            ? SevaReminderScheduler::humanLabel((int) $state['offset_minutes'])
                                .' before → '.($state['recipient_type'] ?? '').' → '.($state['channel'] ?? '')
                            : null),
                ]),

            Forms\Components\Section::make('Product Selection for Devotee')
                ->icon('heroicon-o-squares-2x2')
                ->collapsed()
                ->schema([
                    Forms\Components\Toggle::make('enable_product_selection')
                        ->label('Enable product selection during booking')
                        ->helperText('Devotee will see linked products as visual options during seva booking.')
                        ->live()
                        ->afterStateHydrated(function ($component, $state, $record) {
                            if ($record && ! empty($record->linked_products)) {
                                $component->state(true);
                            }
                        }),
                    Forms\Components\Select::make('linked_products.type')
                        ->label('Link by')
                        ->options(['products' => 'Individual Products', 'category' => 'Entire Category'])
                        ->default('products')
                        ->live()
                        ->visible(fn (Get $get) => (bool) $get('enable_product_selection')),
                    Forms\Components\Select::make('linked_products.product_ids')
                        ->label('Select Products')
                        ->multiple()
                        ->options(fn () => Product::where('is_active', true)->pluck('name_en', 'id'))
                        ->searchable()
                        ->preload()
                        ->visible(fn (Get $get) => $get('enable_product_selection') && $get('linked_products.type') === 'products'),
                    Forms\Components\Select::make('linked_products.category_id')
                        ->label('Select Category')
                        ->options(fn () => ProductCategory::where('is_active', true)->pluck('name_en', 'id'))
                        ->searchable()
                        ->preload()
                        ->visible(fn (Get $get) => $get('enable_product_selection') && $get('linked_products.type') === 'category'),
                    Forms\Components\TextInput::make('linked_products.label_gu')
                        ->label('Selection Label (Gujarati)')
                        ->placeholder('દા.ત. વસ્ત્ર પસંદ કરો')
                        ->visible(fn (Get $get) => (bool) $get('enable_product_selection')),
                    Forms\Components\TextInput::make('linked_products.label_hi')
                        ->label('Selection Label (Hindi)')
                        ->placeholder('उदा. वस्त्र चुनें')
                        ->visible(fn (Get $get) => (bool) $get('enable_product_selection')),
                    Forms\Components\TextInput::make('linked_products.label_en')
                        ->label('Selection Label (English)')
                        ->placeholder('e.g. Choose Vastra')
                        ->visible(fn (Get $get) => (bool) $get('enable_product_selection')),
                ])->columns(2),

            Forms\Components\Section::make('Notification Image')
                ->icon('heroicon-o-photo')
                ->collapsed()
                ->description('Which picture this seva\'s booking confirmation and reminder messages carry — the {{ image_url }} variable. One WhatsApp template serves every seva, so this setting is what makes the same template send a different image per seva.')
                ->schema([
                    Forms\Components\Radio::make('notification_image_source')
                        ->label('Image to send')
                        ->options(Seva::imageSourceOptions())
                        ->default(Seva::IMAGE_SOURCE_PRODUCT)
                        ->required()
                        ->helperText('Each option falls back to the other if its image is missing, and to the trust-wide default image in System Settings → General if both are. Pick "No image" only for templates with no image header — WhatsApp rejects a message whose image header has no link.'),
                ]),

            Forms\Components\Section::make('Extra Fields (asked at booking)')
                ->icon('heroicon-o-clipboard-document-list')
                ->description('Optional questions the devotee answers when booking this seva — a name, a date, a photo. Answers can be placed on the greeting card below.')
                ->collapsed()
                ->schema([
                    \App\Filament\Support\ExtraFieldsRepeater::make(
                        'Asked on the seva booking form, on the website and in the app.'
                    ),
                ]),

            Forms\Components\Section::make('Greeting Card')
                ->icon('heroicon-o-photo')
                ->description('Optional: devotees receive a greeting card image after booking this seva, rendered in their preferred language. Upload a background per language, then drag & drop variables on the canvas. Delivery channels are controlled by the "Seva — greeting card" notification templates.')
                ->collapsed()
                ->schema([
                    Forms\Components\FileUpload::make('greeting_card_template')
                        ->label('Background Template Image (Gujarati / default)')
                        ->directory('greeting-templates')
                        ->image()
                        ->maxSize(5120)
                        ->helperText('Recommended: 1200x800px PNG or JPG. The overlay layout below is positioned on THIS image and is shared by all languages.')
                        ->columnSpanFull()
                        ->live(),

                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\FileUpload::make('greeting_card_template_hi')
                            ->label('Background (Hindi)')
                            ->directory('greeting-templates')
                            ->image()
                            ->maxSize(5120)
                            ->helperText('Optional. MUST be the same dimensions as the Gujarati image — falls back to Gujarati when empty.'),
                        Forms\Components\FileUpload::make('greeting_card_template_en')
                            ->label('Background (English)')
                            ->directory('greeting-templates')
                            ->image()
                            ->maxSize(5120)
                            ->helperText('Optional. MUST be the same dimensions as the Gujarati image — falls back to Gujarati when empty.'),
                    ]),

                    Forms\Components\Placeholder::make('card_editor_ui')
                        ->content(fn ($record) => view('filament.components.greeting-card-editor', [
                            'record' => $record,
                            'statePath' => 'data.greeting_card_config',
                            'availableVars' => array_merge([
                                ['key' => '_donor_name', 'label' => 'Devotee Name', 'type' => 'text', 'auto' => true],
                                ['key' => '_seva_name', 'label' => 'Seva Name', 'type' => 'text', 'auto' => true],
                                ['key' => '_booking_date', 'label' => 'Booking Date', 'type' => 'text', 'auto' => true],
                                ['key' => '_slot', 'label' => 'Slot', 'type' => 'text', 'auto' => true],
                                ['key' => '_amount', 'label' => 'Amount', 'type' => 'text', 'auto' => true],
                                ['key' => '_sankalp', 'label' => 'Sankalp', 'type' => 'text', 'auto' => true],
                                ['key' => '_date', 'label' => 'Date', 'type' => 'text', 'auto' => true],
                                ['key' => '_temple_name', 'label' => 'Temple Name', 'type' => 'text', 'auto' => true],
                            ], \App\Filament\Support\ExtraFieldsRepeater::asCardVariables($record?->extra_fields)),
                        ]))
                        ->columnSpanFull(),

                    Forms\Components\Hidden::make('greeting_card_config')
                        ->dehydrateStateUsing(function ($state) {
                            if (is_string($state)) {
                                $decoded = json_decode($state, true);

                                return is_array($decoded) ? $decoded : $state;
                            }

                            return $state;
                        }),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\ImageColumn::make('image_path')->label('Image')->circular(),
                Tables\Columns\TextColumn::make('name_gu')->label('Name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('category')->badge()->formatStateUsing(fn ($state) => ucfirst((string) $state))->color('gray'),
                Tables\Columns\TextColumn::make('price')->prefix('₹')->sortable(),
                Tables\Columns\TextColumn::make('assignee.name')->label('Assignee')->searchable()->toggleable(),
                Tables\Columns\ToggleColumn::make('is_active')->label('Active'),
                Tables\Columns\TextColumn::make('sort_order')->sortable(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->options(fn (): array => \App\Models\SevaCategory::orderBy('sort_order')
                        ->get()
                        ->mapWithKeys(fn ($c) => [$c->slug => $c->name_en ?? $c->name_gu])
                        ->all()),
                Tables\Filters\TernaryFilter::make('is_active')->label('Active'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }


    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSevas::route('/'),
            'create' => Pages\CreateSeva::route('/create'),
            'edit' => Pages\EditSeva::route('/{record}/edit'),
        ];
    }
}
