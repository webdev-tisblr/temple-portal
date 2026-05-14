<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\NotificationLogResource\Pages;
use App\Models\NotificationLog;
use App\Services\Notifications\NotificationService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification as FlashNotification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only audit surface over the per-attempt notification log written
 * by NotificationService. Lets the trust admin answer "did the donor
 * actually receive their 80G receipt email?" without grepping logs.
 *
 * Create is hard-disabled — log rows are produced exclusively by the
 * service. Edit is disabled too; the Resend action is the only way to
 * trigger a fresh attempt.
 */
class NotificationLogResource extends Resource
{
    protected static ?string $model = NotificationLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static ?string $navigationGroup = 'Communication';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'Notification log';

    protected static ?string $pluralModelLabel = 'Notification logs';

    // Permission gates — Shield's policy is auto-discovered but we add an
    // explicit hard-block on create so even a super admin can't insert rows.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Dispatch')->schema([
                Forms\Components\TextInput::make('template_key')->disabled(),
                Forms\Components\TextInput::make('channel')->disabled(),
                Forms\Components\TextInput::make('status')->disabled(),
                Forms\Components\TextInput::make('attempts')->disabled(),
                Forms\Components\DateTimePicker::make('dispatched_at')->disabled(),
                Forms\Components\DateTimePicker::make('sent_at')->disabled(),
            ])->columns(3),
            Forms\Components\Section::make('Recipient')->schema([
                Forms\Components\TextInput::make('recipient_masked')->disabled()->label('Recipient (masked)'),
                Forms\Components\TextInput::make('devotee.name')->disabled()->label('Devotee'),
                Forms\Components\TextInput::make('idempotency_key')->disabled(),
            ])->columns(3),
            Forms\Components\Section::make('Outcome')->schema([
                Forms\Components\TextInput::make('skip_reason')->disabled(),
                Forms\Components\Textarea::make('error_message')->disabled()->rows(3),
                Forms\Components\TextInput::make('provider_response_code')->disabled(),
            ]),
            Forms\Components\Section::make('Context snapshot')->schema([
                Forms\Components\KeyValue::make('context_snapshot')
                    ->disabled()
                    ->label('Redacted dispatch context'),
            ])->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('d M H:i:s')
                    ->label('When')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('template_key')
                    ->label('Trigger')
                    ->searchable()
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('channel')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'email' => 'info',
                        'whatsapp' => 'success',
                        'sms' => 'warning',
                        'push' => 'primary',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('recipient_masked')
                    ->label('To')
                    ->searchable()
                    ->limit(40),

                Tables\Columns\TextColumn::make('devotee.name')
                    ->label('Devotee')
                    ->toggleable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        NotificationLog::STATUS_SENT => 'success',
                        NotificationLog::STATUS_FAILED => 'danger',
                        NotificationLog::STATUS_SKIPPED => 'warning',
                        default => 'gray',
                    })
                    ->icon(fn (string $state): string => match ($state) {
                        NotificationLog::STATUS_SENT => 'heroicon-m-check-circle',
                        NotificationLog::STATUS_FAILED => 'heroicon-m-x-circle',
                        NotificationLog::STATUS_SKIPPED => 'heroicon-m-no-symbol',
                        default => 'heroicon-m-clock',
                    }),

                Tables\Columns\TextColumn::make('attempts')
                    ->label('#')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('skip_reason')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('error_message')
                    ->limit(60)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('channel')
                    ->options([
                        'email' => 'Email',
                        'whatsapp' => 'WhatsApp',
                        'sms' => 'SMS',
                        'push' => 'Push',
                    ])
                    ->multiple(),

                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        NotificationLog::STATUS_PENDING => 'Pending',
                        NotificationLog::STATUS_SENT => 'Sent',
                        NotificationLog::STATUS_FAILED => 'Failed',
                        NotificationLog::STATUS_SKIPPED => 'Skipped',
                    ])
                    ->multiple(),

                Tables\Filters\SelectFilter::make('template_key')
                    ->options(fn () => NotificationLog::query()
                        ->select('template_key')
                        ->distinct()
                        ->orderBy('template_key')
                        ->pluck('template_key', 'template_key')
                        ->all())
                    ->searchable()
                    ->label('Trigger'),

                Tables\Filters\Filter::make('failed_today')
                    ->label('Failed today')
                    ->query(fn ($query) => $query
                        ->where('status', NotificationLog::STATUS_FAILED)
                        ->whereDate('created_at', today())),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('resend')
                    ->label('Resend')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (NotificationLog $record): bool =>
                        in_array($record->status, [NotificationLog::STATUS_FAILED, NotificationLog::STATUS_SKIPPED], true)
                        && auth('admin')->user()?->can('resend_notification')
                    )
                    ->requiresConfirmation()
                    ->action(function (NotificationLog $record) {
                        $template = $record->template;
                        if ($template === null) {
                            FlashNotification::make()
                                ->title('Source template no longer exists')
                                ->danger()
                                ->send();
                            return;
                        }

                        // Rebuild a minimal context from the snapshot. For
                        // rich Eloquent context the resend will be partial
                        // (only the keys that flattened cleanly into the
                        // snapshot survive) — admin can also re-trigger
                        // the upstream event for a full reproduction.
                        $context = $record->context_snapshot ?? [];

                        $ok = app(NotificationService::class)->sendTemplate($template, $context);

                        FlashNotification::make()
                            ->title($ok ? 'Resent' : 'Resend failed — see latest log row')
                            ->color($ok ? 'success' : 'danger')
                            ->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotificationLogs::route('/'),
            'view' => Pages\ViewNotificationLog::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        // Eager-load relations to avoid N+1 on the list view.
        return parent::getEloquentQuery()->with(['template', 'devotee']);
    }
}
