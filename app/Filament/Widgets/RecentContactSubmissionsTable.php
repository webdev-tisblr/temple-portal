<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\ContactSubmissionResource;
use App\Models\ContactSubmission;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Mirror of RecentDonationsTable for the inbox side of the dashboard.
 * The unread badge gives the admin a one-glance signal of whether
 * anyone needs a reply; row tap jumps to the resource edit page
 * which has the full message + reply context.
 */
class RecentContactSubmissionsTable extends BaseWidget
{
    protected static ?string $heading = 'Recent messages';
    protected static ?int $sort = 8;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth('admin')->user()?->can('widget_RecentContactSubmissionsTable') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                ContactSubmission::query()->latest()->limit(5),
            )
            ->columns([
                Tables\Columns\IconColumn::make('is_read')
                    ->label('')
                    ->boolean()
                    ->trueIcon('heroicon-o-envelope-open')
                    ->falseIcon('heroicon-s-envelope')
                    ->trueColor('gray')
                    ->falseColor('danger'),
                Tables\Columns\TextColumn::make('name')
                    ->limit(24),
                Tables\Columns\TextColumn::make('subject')
                    ->limit(32)
                    ->placeholder('—')
                    ->wrap(),
                Tables\Columns\TextColumn::make('phone')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')
                    ->since()
                    ->label('When'),
            ])
            ->paginated(false)
            ->recordUrl(fn (ContactSubmission $record): string =>
                ContactSubmissionResource::getUrl('edit', ['record' => $record])
            );
    }
}
