<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Donation;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentDonationsTable extends BaseWidget
{
    protected static ?string $heading = 'Recent Donations';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(Donation::with('devotee')->latest()->limit(10))
            ->columns([
                Tables\Columns\TextColumn::make('devotee.name')->label('Devotee')->default('Anonymous'),
                Tables\Columns\TextColumn::make('amount')->prefix('₹')->sortable(),
                Tables\Columns\TextColumn::make('donation_type')->badge(),
                Tables\Columns\TextColumn::make('created_at')->since()->label('Time'),
            ])
            ->paginated(false);
    }
}
