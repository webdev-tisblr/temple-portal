<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\StatusTemplateResource\Pages;
use App\Filament\Support\TranslatableTabs;
use App\Models\StatusTemplate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Greeting/status templates devotees personalise on demand in the app
 * (name + photo) and share to WhatsApp/socials. Reuses the greeting-card
 * drag-drop overlay editor — the model exposes the same column names.
 */
class StatusTemplateResource extends Resource
{
    protected static ?string $model = StatusTemplate::class;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';
    protected static ?string $navigationGroup = 'Content Management';
    protected static ?string $navigationLabel = 'Status Templates';
    protected static ?string $modelLabel = 'Status Template';
    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Template')->schema([
                TranslatableTabs::make(fn (string $locale, string $label) => [
                    Forms\Components\TextInput::make("title_{$locale}")
                        ->label("Title {$label}")
                        ->required($locale === 'gu')
                        ->maxLength(200),
                    Forms\Components\Textarea::make("share_text_{$locale}")
                        ->label("Share Caption {$label}")
                        ->rows(3)
                        ->maxLength(1000)
                        ->helperText('Optional. Sent with the image when a devotee shares this status. Leave blank to share the image with no text.'),
                ]),
                Forms\Components\FileUpload::make('greeting_card_template')
                    ->label('Background Template Image')
                    ->directory('status-templates')
                    ->image()
                    ->maxSize(5120)
                    ->required()
                    ->helperText('Recommended 1080×1920 (WhatsApp status) PNG or JPG.')
                    ->columnSpanFull()
                    ->live(),
            ]),

            Forms\Components\Section::make('Personalisation Slots')
                ->description('Drag the devotee\'s name / photo slots onto the design. Save after uploading the image to enable the canvas.')
                ->schema([
                    Forms\Components\Placeholder::make('card_editor_ui')
                        ->hiddenLabel()
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
                ]),

            Forms\Components\Section::make('Status')->schema([
                Forms\Components\Toggle::make('is_active')->label('Active')->default(true),
                Forms\Components\TextInput::make('sort_order')->numeric()->default(0),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('greeting_card_template')->label('Template')->height(60),
                Tables\Columns\TextColumn::make('title_gu')->label('Title')->searchable(),
                Tables\Columns\ToggleColumn::make('is_active')->label('Active'),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([Tables\Actions\DeleteBulkAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStatusTemplates::route('/'),
            'create' => Pages\CreateStatusTemplate::route('/create'),
            'edit' => Pages\EditStatusTemplate::route('/{record}/edit'),
        ];
    }
}
