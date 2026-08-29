<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\DarshanCardTemplateResource\Pages;
use App\Filament\Support\CardTemplateUpload;
use App\Models\DarshanCardTemplate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Admin-designed daily-darshan share card templates (story + square).
 * Reuses the greeting-card drag-drop overlay editor. When no active
 * template exists for a format, the app falls back to the built-in
 * drawn design (DarshanShareCardService).
 */
class DarshanCardTemplateResource extends Resource
{
    protected static ?string $model = DarshanCardTemplate::class;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationGroup = 'Content Management';

    protected static ?string $navigationLabel = 'Darshan Card Templates';

    protected static ?string $modelLabel = 'Darshan Card Template';

    protected static ?int $navigationSort = 21;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Template')
                ->description('One template per card format. When a format has no active template, devotees get the built-in card design instead.')
                ->schema([
                    Forms\Components\Select::make('format')
                        ->label('Card Format')
                        ->options([
                            DarshanCardTemplate::FORMAT_STORY => 'Story — 1080 × 1920 (WhatsApp status / Instagram story)',
                            DarshanCardTemplate::FORMAT_SQUARE => 'Square — 1080 × 1080 (feed post)',
                        ])
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->native(false),

                    CardTemplateUpload::make(
                        'greeting_card_template',
                        'darshan-card-templates',
                        ['9:16', '1:1', null],
                        'Background Image (Gujarati / default)',
                        'Story: crop to 9:16 (1080×1920). Square: crop to 1:1 (1080×1080). Upload the full-resolution original — it is stored downscaled and re-encoded. The overlay layout below is positioned on THIS image and is shared by all languages.',
                    )
                        ->required()
                        ->columnSpanFull()
                        ->live(),

                    Forms\Components\Grid::make(2)->schema([
                        CardTemplateUpload::make(
                            'greeting_card_template_hi',
                            'darshan-card-templates',
                            ['9:16', '1:1', null],
                            'Background (Hindi)',
                            'Optional. Crop it to the SAME shape as the Gujarati image — the overlay layout is shared. Falls back to Gujarati when empty.',
                        ),
                        CardTemplateUpload::make(
                            'greeting_card_template_en',
                            'darshan-card-templates',
                            ['9:16', '1:1', null],
                            'Background (English)',
                            'Optional. Crop it to the SAME shape as the Gujarati image — the overlay layout is shared. Falls back to Gujarati when empty.',
                        ),
                    ]),
                ]),

            Forms\Components\Section::make('Card Layout')
                ->description('Drag the darshan photo slot, devotee name/photo and text variables onto the design. Save after uploading the background to enable the canvas.')
                ->schema([
                    Forms\Components\Placeholder::make('card_editor_ui')
                        ->hiddenLabel()
                        ->content(fn ($record) => view('filament.components.greeting-card-editor', [
                            'record' => $record,
                            'statePath' => 'data.greeting_card_config',
                            'availableVars' => DarshanCardTemplate::editorVars(),
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

            Forms\Components\Section::make('Status')->schema([
                Forms\Components\Toggle::make('is_active')->label('Active')->default(true)
                    ->helperText('Inactive = devotees get the built-in card design for this format.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('greeting_card_template')->label('Background')->height(60),
                Tables\Columns\TextColumn::make('format')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state === DarshanCardTemplate::FORMAT_STORY ? 'Story 1080×1920' : 'Square 1080×1080'),
                Tables\Columns\ToggleColumn::make('is_active')->label('Active'),
                Tables\Columns\TextColumn::make('updated_at')->label('Updated')->dateTime('d M Y H:i')->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([Tables\Actions\DeleteBulkAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDarshanCardTemplates::route('/'),
            'create' => Pages\CreateDarshanCardTemplate::route('/create'),
            'edit' => Pages\EditDarshanCardTemplate::route('/{record}/edit'),
        ];
    }
}
