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

    protected static ?string $navigationGroup = 'Donations & Finance';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Basic Info')->schema([
                Forms\Components\TextInput::make('title_gu')->label('Title (Gujarati)')->required()->maxLength(500),
                Forms\Components\TextInput::make('title_hi')->label('Title (Hindi)')->maxLength(500),
                Forms\Components\TextInput::make('title_en')->label('Title (English)')->maxLength(500)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug($state ?? ''))),
                Forms\Components\TextInput::make('slug')->required()->unique(ignoreRecord: true)->maxLength(255),
                Forms\Components\FileUpload::make('image_path')->label('Cover Image')->image()->directory('campaigns')->maxSize(2048),
            ])->columns(2),

            Forms\Components\Section::make('Description')->schema([
                Forms\Components\Textarea::make('description_gu')->label('Description (Gujarati)')->rows(3),
                Forms\Components\Textarea::make('description_hi')->label('Description (Hindi)')->rows(3),
                Forms\Components\Textarea::make('description_en')->label('Description (English)')->rows(3),
            ])->columns(1),

            Forms\Components\Section::make('Detailed Writeup')->schema([
                Forms\Components\RichEditor::make('writeup_gu')->label('Writeup (Gujarati)'),
                Forms\Components\RichEditor::make('writeup_hi')->label('Writeup (Hindi)'),
                Forms\Components\RichEditor::make('writeup_en')->label('Writeup (English)'),
            ]),

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
                            Forms\Components\TextInput::make('title_gu')->label('Title (Gujarati)')->required()->maxLength(255),
                            Forms\Components\TextInput::make('title_hi')->label('Title (Hindi)')->maxLength(255),
                            Forms\Components\TextInput::make('title_en')->label('Title (English)')->maxLength(255),
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
                        Forms\Components\TextInput::make('question_gu')->label('Question (Gujarati)')->maxLength(500),
                        Forms\Components\TextInput::make('question_hi')->label('Question (Hindi)')->maxLength(500),
                        Forms\Components\TextInput::make('question_en')->label('Question (English)')->maxLength(500),
                        Forms\Components\Textarea::make('answer_gu')->label('Answer (Gujarati)')->rows(3),
                        Forms\Components\Textarea::make('answer_hi')->label('Answer (Hindi)')->rows(3),
                        Forms\Components\Textarea::make('answer_en')->label('Answer (English)')->rows(3),
                    ])
                    ->columns(1)
                    ->defaultItems(0)
                    ->addActionLabel('Add FAQ'),
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
