<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SevaSlotPoolResource\Pages;
use App\Filament\Support\SlotConfigFields;
use App\Models\SevaSlotPool;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Shared slot pools: one set of slot settings that several sevas follow
 * together. Booking ANY member seva consumes the pool's capacity, so all
 * members always show identical availability.
 */
class SevaSlotPoolResource extends Resource
{
    protected static ?string $model = SevaSlotPool::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?string $navigationGroup = 'Portal Setup';

    protected static ?string $navigationLabel = 'Seva Slot Pools';

    protected static ?string $modelLabel = 'Seva Slot Pool';

    protected static ?int $navigationSort = 31;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Pool')
                ->description('Sevas that select this pool share ONE booking capacity: if the pool has 5 slots per day and 3 are booked via Seva A, Sevas B and C also show only 2 left. Attach sevas from each seva\'s Edit page → Slot Configuration → Shared Slot Pool.')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Pool Name')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('e.g. Morning Aarti Sevas')
                        ->helperText('Internal admin label — never shown to devotees.'),
                ]),

            Forms\Components\Section::make('Slot Configuration')
                ->icon('heroicon-o-clock')
                ->description('These settings apply to EVERY seva in this pool (each member\'s own slot settings are ignored while pooled).')
                ->schema(SlotConfigFields::schema()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Pool')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('slot_config.slot_type')
                    ->label('Mode')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'full_day' => 'Full day',
                        'full_week' => 'Full week',
                        default => 'Time slots',
                    })
                    ->color('gray'),
                Tables\Columns\TextColumn::make('slot_config.max_bookings_per_slot')
                    ->label('Capacity')
                    ->badge(),
                Tables\Columns\TextColumn::make('sevas_count')
                    ->label('Member Sevas')
                    ->counts('sevas')
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('updated_at')->label('Updated')->dateTime('d M Y H:i')->sortable(),
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
            'index' => Pages\ListSevaSlotPools::route('/'),
            'create' => Pages\CreateSevaSlotPool::route('/create'),
            'edit' => Pages\EditSevaSlotPool::route('/{record}/edit'),
        ];
    }
}
