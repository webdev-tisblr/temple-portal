<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\NotificationResource\Pages;
use App\Models\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class NotificationResource extends Resource
{
    protected static ?string $model = Notification::class;
    protected static ?string $navigationIcon = 'heroicon-o-bell';
    protected static ?string $navigationGroup = 'Communication';
    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title_gu')->label('Title')->searchable()->limit(40),
                Tables\Columns\TextColumn::make('segment')->badge(),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                    'sent' => 'success', 'scheduled' => 'info', 'sending' => 'warning',
                    'failed' => 'danger', default => 'gray',
                }),
                Tables\Columns\TextColumn::make('scheduled_at')->dateTime('d M Y H:i'),
                Tables\Columns\TextColumn::make('sent_at')->dateTime('d M Y H:i'),
                Tables\Columns\TextColumn::make('total_recipients')->label('Recipients'),
                Tables\Columns\TextColumn::make('delivered_count')->label('Delivered'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'draft' => 'Draft', 'scheduled' => 'Scheduled', 'sending' => 'Sending',
                    'sent' => 'Sent', 'failed' => 'Failed',
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotifications::route('/'),
        ];
    }
}
