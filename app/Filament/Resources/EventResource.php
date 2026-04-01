<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\EventType;
use App\Filament\Resources\EventResource\Pages;
use App\Models\Event;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class EventResource extends Resource
{
    protected static ?string $model = Event::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Content';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Title')->schema([
                Forms\Components\TextInput::make('title_gu')->label('Title (Gujarati)')->required()->maxLength(500),
                Forms\Components\TextInput::make('title_hi')->label('Title (Hindi)')->maxLength(500),
                Forms\Components\TextInput::make('title_en')->label('Title (English)')->maxLength(500),
            ])->columns(3),

            Forms\Components\Section::make('Description')->schema([
                Forms\Components\RichEditor::make('description_gu')->label('Description (Gujarati)'),
                Forms\Components\RichEditor::make('description_hi')->label('Description (Hindi)'),
                Forms\Components\RichEditor::make('description_en')->label('Description (English)'),
            ]),

            Forms\Components\Section::make('Schedule')->schema([
                Forms\Components\DatePicker::make('start_date')->required(),
                Forms\Components\DatePicker::make('end_date'),
                Forms\Components\TimePicker::make('start_time'),
                Forms\Components\TimePicker::make('end_time'),
                Forms\Components\TextInput::make('location')->default('Shree Pataliya Hanumanji Temple, Antarjal'),
            ])->columns(2),

            Forms\Components\Section::make('Settings')->schema([
                Forms\Components\Select::make('event_type')->options(
                    collect(EventType::cases())->mapWithKeys(fn ($c) => [$c->value => ucfirst(str_replace('_', ' ', $c->value))])
                )->required(),
                Forms\Components\Select::make('status')->options(['draft' => 'Draft', 'published' => 'Published', 'cancelled' => 'Cancelled'])->default('draft'),
                Forms\Components\Toggle::make('is_featured')->label('Featured'),
                Forms\Components\FileUpload::make('image_path')->image()->directory('events')->maxSize(2048),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title_gu')->label('Title')->searchable()->sortable()->limit(40),
                Tables\Columns\TextColumn::make('start_date')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('end_date')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('event_type')->badge()->formatStateUsing(fn ($state) => ucfirst(str_replace('_', ' ', $state->value ?? $state))),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                    'published' => 'success', 'draft' => 'warning', 'cancelled' => 'danger', default => 'gray',
                }),
                Tables\Columns\IconColumn::make('is_featured')->boolean()->label('Featured'),
            ])
            ->defaultSort('start_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(['draft' => 'Draft', 'published' => 'Published', 'cancelled' => 'Cancelled']),
                Tables\Filters\SelectFilter::make('event_type')->options(
                    collect(EventType::cases())->mapWithKeys(fn ($c) => [$c->value => ucfirst(str_replace('_', ' ', $c->value))])
                ),
                Tables\Filters\TernaryFilter::make('is_featured'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEvents::route('/'),
            'create' => Pages\CreateEvent::route('/create'),
            'edit' => Pages\EditEvent::route('/{record}/edit'),
        ];
    }
}
