<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\DonationCampaignResource\Pages;
use App\Models\DonationCampaign;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class DonationCampaignResource extends Resource
{

    protected static ?string $model = DonationCampaign::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Booking & Donation Reports';

    protected static ?int $navigationSort = 30;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Content')->schema([
                \App\Filament\Support\TranslatableTabs::make(function (string $locale, string $label) {
                    $title = Forms\Components\TextInput::make("title_{$locale}")->label("Title {$label}")->required($locale === 'gu')->maxLength(500);
                    if ($locale === 'en') {
                        $title->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug($state ?? '')));
                    }

                    return [
                        $title,
                        Forms\Components\Textarea::make("description_{$locale}")->label("Short Description {$label}")->rows(3),
                        Forms\Components\RichEditor::make("writeup_{$locale}")->label("Detailed Writeup {$label}"),
                    ];
                }),
                Forms\Components\TextInput::make('slug')->required()->unique(ignoreRecord: true)->maxLength(255),
                Forms\Components\FileUpload::make('image_path')->label('Cover Image')->image()->directory('campaigns')->maxSize(2048),
            ])->columns(2),

            Forms\Components\Section::make('Featured Video')->schema([
                Forms\Components\TextInput::make('featured_video_url')
                    ->label('Featured Video URL')
                    ->url()
                    ->maxLength(500)
                    ->placeholder('https://youtu.be/xxxxxxxxxxx')
                    ->helperText('Optional. Paste a YouTube link (or a direct .mp4 URL). Shows prominently above the image gallery on web and app.'),
            ]),

            Forms\Components\Section::make('Media Gallery')->schema([
                Forms\Components\Repeater::make('media')
                    ->schema([
                        Forms\Components\FileUpload::make('url')->label('File')
                            ->directory('campaign-media')
                            ->acceptedFileTypes(['image/*', 'video/*'])
                            ->maxSize(10240),
                        Forms\Components\Select::make('type')
                            ->options([
                                'image' => 'Image',
                                'video' => 'Video',
                            ])
                            ->default('image'),
                        Forms\Components\TextInput::make('sort_order')->numeric()->default(0),
                    ])
                    ->columns(3)
                    ->defaultItems(0)
                    ->addActionLabel('Add Media'),
            ]),

            Forms\Components\Section::make('Sub-Causes')
                ->description('Optional. Split the campaign into specific causes a donor can choose to fund (e.g. Roof repair, Annadan). If none are added, donors give to the campaign as a whole.')
                ->schema([
                    Forms\Components\Repeater::make('subCauses')
                        ->relationship()
                        ->schema([
                            \App\Filament\Support\TranslatableTabs::make(fn (string $locale, string $label) => [
                                Forms\Components\TextInput::make("title_{$locale}")->label("Title {$label}")->required($locale === 'gu')->maxLength(255),
                            ]),
                            Forms\Components\TextInput::make('goal_amount')->label('Goal Amount (optional)')->numeric()->prefix('₹'),
                            Forms\Components\Toggle::make('is_active')->label('Active')->default(true)->inline(false),
                            Forms\Components\TextInput::make('sort_order')->numeric()->default(0),
                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->reorderable()
                        ->orderColumn('sort_order')
                        ->addActionLabel('Add Sub-Cause'),
                ]),

            Forms\Components\Section::make('FAQs')->schema([
                Forms\Components\Repeater::make('faqs')
                    ->schema([
                        \App\Filament\Support\TranslatableTabs::make(fn (string $locale, string $label) => [
                            Forms\Components\TextInput::make("question_{$locale}")->label("Question {$label}")->maxLength(500),
                            Forms\Components\Textarea::make("answer_{$locale}")->label("Answer {$label}")->rows(3),
                        ]),
                    ])
                    ->columns(1)
                    ->defaultItems(0)
                    ->addActionLabel('Add FAQ'),
            ]),

            // Mirrors the identical section on SevaResource / DonationTypeResource.
            // Campaign donations carry no donation_type_id (the donate form hides
            // the type picker in campaign mode), so without artwork HERE a
            // campaign gift can never produce a card at all.
            Forms\Components\Section::make('Extra Fields (asked at donation)')
                ->icon('heroicon-o-clipboard-document-list')
                ->description('Optional questions a donor answers when giving to THIS campaign — a name to dedicate it to, a photo. Answers can be placed on the greeting card below.')
                ->collapsed()
                ->schema([
                    \App\Filament\Support\ExtraFieldsRepeater::make(
                        'Asked on the campaign donation form, on the website and in the app.'
                    ),
                ]),

            Forms\Components\Section::make('Greeting Card')
                ->icon('heroicon-o-photo')
                ->description('Optional: donors to this campaign receive a greeting card image, rendered in their preferred language. Upload a background per language, then drag & drop variables on the canvas. Nothing is sent until the "Donation — campaign greeting card" notification template is created and enabled.')
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
                            // Campaigns have no extra_fields, so the built-ins are
                            // the whole set — same situation as Seva.
                            'availableVars' => array_merge([
                                ['key' => '_donor_name', 'label' => 'Devotee Name', 'type' => 'text', 'auto' => true],
                                ['key' => '_campaign_title', 'label' => 'Campaign Name', 'type' => 'text', 'auto' => true],
                                ['key' => '_amount', 'label' => 'Amount', 'type' => 'text', 'auto' => true],
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

            Forms\Components\Section::make('Settings')->schema([
                Forms\Components\TextInput::make('goal_amount')->label('Goal Amount')->numeric()->prefix('₹')->required(),
                Forms\Components\DatePicker::make('start_date')->required(),
                Forms\Components\Toggle::make('is_active')->label('Active')->default(true),
                Forms\Components\Toggle::make('is_featured')->label('Featured')->default(false),
                Forms\Components\Toggle::make('show_donor_list')->label('Show Donor List')->default(true),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image_path')->label('Image')->circular(),
                Tables\Columns\TextColumn::make('title_gu')->label('Title')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('goal_amount')->label('Goal')->prefix('₹')->sortable(),
                Tables\Columns\TextColumn::make('raised_amount')->label('Raised')->prefix('₹')->sortable(),
                Tables\Columns\TextColumn::make('progress')->label('Progress')
                    ->formatStateUsing(function ($record) {
                        if ((float) $record->goal_amount <= 0) {
                            return '0%';
                        }
                        return round(((float) $record->raised_amount / (float) $record->goal_amount) * 100) . '%';
                    })
                    ->state(fn ($record) => $record),
                Tables\Columns\ToggleColumn::make('is_active')->label('Active'),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Active'),
                Tables\Filters\TernaryFilter::make('is_featured')->label('Featured'),
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
            'index' => Pages\ListDonationCampaigns::route('/'),
            'create' => Pages\CreateDonationCampaign::route('/create'),
            'edit' => Pages\EditDonationCampaign::route('/{record}/edit'),
        ];
    }
}
