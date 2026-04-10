<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\DonationTypeResource\Pages;
use App\Models\DonationType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class DonationTypeResource extends Resource
{
    protected static ?string $model = DonationType::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Donations & Finance';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Basic Info')->schema([
                Forms\Components\TextInput::make('name_gu')
                    ->label('Name (Gujarati)')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('name_hi')
                    ->label('Name (Hindi)')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('name_en')
                    ->label('Name (English)')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug($state ?? ''))),
                Forms\Components\TextInput::make('slug')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                Forms\Components\Textarea::make('description')
                    ->label('Description')
                    ->columnSpanFull(),
            ])->columns(2),

            Forms\Components\Section::make('Extra Fields (Dynamic Form Builder)')->schema([
                Forms\Components\Repeater::make('extra_fields')
                    ->schema([
                        Forms\Components\TextInput::make('key')
                            ->required()
                            ->placeholder('e.g. birthday_person_name')
                            ->rules(['alpha_dash']),
                        Forms\Components\TextInput::make('label_gu')
                            ->required()
                            ->placeholder('ગુજરાતી લેબલ'),
                        Forms\Components\TextInput::make('label_en')
                            ->required()
                            ->placeholder('English Label'),
                        Forms\Components\Select::make('type')
                            ->options([
                                'text' => 'Text',
                                'number' => 'Number',
                                'date' => 'Date',
                                'image' => 'Image',
                                'textarea' => 'Textarea',
                            ])
                            ->required(),
                        Forms\Components\Toggle::make('required')
                            ->default(false),
                    ])
                    ->columns(3)
                    ->defaultItems(0)
                    ->addActionLabel('Add Field')
                    ->collapsible()
                    ->columnSpanFull(),
            ]),

            Forms\Components\Section::make('Greeting Card')
                ->icon('heroicon-o-photo')
                ->description('Upload a background, then drag & drop text/image overlays on the visual canvas.')
                ->schema([
                    Forms\Components\FileUpload::make('greeting_card_template')
                        ->label('Background Template Image')
                        ->directory('greeting-templates')
                        ->image()
                        ->maxSize(5120)
                        ->helperText('Recommended: 1200x800px PNG or JPG.')
                        ->columnSpanFull()
                        ->live(),

                    Forms\Components\Placeholder::make('card_editor_ui')
                        ->content(fn ($record) => view('filament.components.greeting-card-editor', [
                            'record' => $record,
                            'statePath' => 'data.greeting_card_config',
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
                ])
                ->collapsible(),

            Forms\Components\Section::make('Greeting Card — Sending')
                ->icon('heroicon-o-paper-airplane')
                ->collapsed()
                ->schema([
                    Forms\Components\Toggle::make('_send_via_email')
                        ->label('Send greeting card via Email')
                        ->default(true)
                        ->helperText('Attached to the 80G receipt email.')
                        ->afterStateHydrated(function ($component, $record) {
                            $config = $record?->greeting_card_config ?? [];
                            $component->state($config['send_via_email'] ?? true);
                        }),
                    Forms\Components\Toggle::make('_send_via_whatsapp')
                        ->label('Send greeting card via WhatsApp')
                        ->default(true)
                        ->helperText('Sent as image message to the devotee.'),
                    Forms\Components\Toggle::make('_show_on_thankyou')
                        ->label('Show on thank-you page with download button')
                        ->default(true)
                        ->helperText('Display the card image after successful payment.'),
                ])->columns(3),

            Forms\Components\Section::make('Status')->schema([
                Forms\Components\Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
                Forms\Components\TextInput::make('sort_order')
                    ->label('Sort Order')
                    ->numeric()
                    ->default(0),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name_gu')
                    ->label('Name (Gujarati)')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('slug')
                    ->label('Slug'),
                Tables\Columns\TextColumn::make('extra_fields')
                    ->label('Extra Fields')
                    ->formatStateUsing(fn ($state) => is_array($state) ? count($state) . ' field(s)' : '0 fields')
                    ->badge(),
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Active'),
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Sort Order')
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
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
            'index' => Pages\ListDonationTypes::route('/'),
            'create' => Pages\CreateDonationType::route('/create'),
            'edit' => Pages\EditDonationType::route('/{record}/edit'),
        ];
    }
}
