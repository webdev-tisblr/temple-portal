<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Donation;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class RecentDonationsTable extends BaseWidget
{
    protected static ?string $heading = 'Recent donations';

    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth('admin')->user()?->can('widget_RecentDonationsTable') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            // Captured-only — the dashboard's "Recent Donations" panel
            // must show actual receipts, not Razorpay handshakes that
            // never completed. Operators monitoring the dashboard in
            // real-time should only see donations the trust received.
            ->query(
                Donation::query()
                    ->whereHas('payment', fn (Builder $q) => $q->where('status', 'captured'))
                    ->with('devotee')
                    ->latest()
                    ->limit(10),
            )
            ->columns([
                Tables\Columns\TextColumn::make('devotee.name')->label('Devotee')->default('Anonymous'),
                Tables\Columns\TextColumn::make('amount')->prefix('₹')->sortable(),
                Tables\Columns\TextColumn::make('donation_type')->badge(),
                Tables\Columns\TextColumn::make('created_at')->since()->label('Time'),
            ])
            ->paginated(false);
    }
}
