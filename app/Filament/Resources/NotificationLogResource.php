<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\NotificationLogResource\Pages;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
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
            // Delivery-status section is populated from the WhatsApp
            // webhook event stream (and any future SMS/email provider
            // webhooks). status above = "did the upstream API accept
            // our request"; delivery_status here = "did the recipient
            // device actually receive it". The two diverge whenever
            // Meta returns 200 to our send call but then fails to
            // deliver the message — exactly the scenario we built the
            // webhook integration to surface.
            Forms\Components\Section::make('Delivery (via provider webhook)')
                ->description('Populated by WhatsAppWebhookController when Meta reports back. Empty here means we have not yet received a delivery status event for this message — either the message has not progressed beyond "accepted by upstream API" yet, or no webhook was wired at the time the send happened.')
                ->schema([
                    Forms\Components\TextInput::make('provider_message_id')->disabled()->label('Provider message ID (Meta wamid)')->columnSpan(2),
                    Forms\Components\TextInput::make('delivery_status')->disabled()->label('Delivery status'),
                    Forms\Components\DateTimePicker::make('delivery_status_at')->disabled()->label('Status updated at'),
                    Forms\Components\Textarea::make('failure_reason')->disabled()->rows(3)->label('Failure reason (Meta error)')->columnSpanFull(),
                ])->columns(3),
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
                    ->label('Send')
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

                // Delivery status from the provider webhook stream.
                // Showed alongside `Send` so admins can see "we sent
                // it but recipient never got it" at a glance — the
                // exact scenario that motivated wiring the webhook.
                Tables\Columns\TextColumn::make('delivery_status')
                    ->label('Delivery')
                    ->badge()
                    ->placeholder('—')
                    ->color(fn (?string $state): string => match ($state) {
                        NotificationLog::DELIVERY_READ => 'success',
                        NotificationLog::DELIVERY_DELIVERED => 'info',
                        NotificationLog::DELIVERY_SENT => 'gray',
                        NotificationLog::DELIVERY_FAILED => 'danger',
                        NotificationLog::DELIVERY_UNDELIVERED => 'warning',
                        default => 'gray',
                    })
                    ->icon(fn (?string $state): string => match ($state) {
                        NotificationLog::DELIVERY_READ => 'heroicon-m-eye',
                        NotificationLog::DELIVERY_DELIVERED => 'heroicon-m-check-badge',
                        NotificationLog::DELIVERY_SENT => 'heroicon-m-paper-airplane',
                        NotificationLog::DELIVERY_FAILED => 'heroicon-m-exclamation-triangle',
                        NotificationLog::DELIVERY_UNDELIVERED => 'heroicon-m-clock',
                        default => '',
                    }),

                Tables\Columns\TextColumn::make('attempts')
                    ->label('#')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('failure_reason')
                    ->label('Why failed')
                    ->limit(80)
                    ->wrap()
                    ->placeholder('—'),

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
                    ->query(fn (\Illuminate\Database\Eloquent\Builder $query) => $query
                        ->where('status', NotificationLog::STATUS_FAILED)
                        ->whereDate('created_at', today())),

                // Catches the "send went out but never arrived" case — the
                // exact pattern the user reported on donation messages.
                // Includes both terminal delivery failures and 'sent' rows
                // that have been stuck without a follow-up status > N min.
                Tables\Filters\Filter::make('delivery_failed_today')
                    ->label('Undelivered today')
                    ->query(fn (\Illuminate\Database\Eloquent\Builder $query) => $query
                        ->whereDate('created_at', today())
                        ->where(function (\Illuminate\Database\Eloquent\Builder $q) {
                            $q->whereIn('delivery_status', [NotificationLog::DELIVERY_FAILED, NotificationLog::DELIVERY_UNDELIVERED])
                                ->orWhere(function (\Illuminate\Database\Eloquent\Builder $q2) {
                                    $q2->where('channel', 'whatsapp')
                                        ->where('status', NotificationLog::STATUS_SENT)
                                        ->whereNull('delivery_status')
                                        ->where('sent_at', '<', now()->subMinutes(5));
                                });
                        })),
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

                        // Clone the template and pin it to the EXACT recipient
                        // (strategy, value) that was tried originally. Without
                        // this, multi-recipient templates fall back to the
                        // template's legacy default (usually `devotee`),
                        // which is the wrong recipient for r1+ entries and
                        // explains the "Resend → skipped/failed" loop.
                        $perRecipientTemplate = (new NotificationTemplate())
                            ->setRawAttributes($template->getAttributes(), sync: true);
                        $perRecipientTemplate->exists = true;
                        if (! empty($record->recipient_strategy)) {
                            $perRecipientTemplate->recipient_strategy = $record->recipient_strategy;
                            $perRecipientTemplate->recipient_value = $record->recipient_value;
                        }

                        // Rehydrate Eloquent model refs in the snapshot. The
                        // snapshotter flattens models to {model, id} pairs so
                        // the JSON column stays bounded; the recipient
                        // resolver expects real instances (`$x instanceof
                        // Model`) so without rehydration the devotee /
                        // booking refs are dead weight at Resend time.
                        $context = NotificationService::rehydrateSnapshot($record->context_snapshot ?? []);

                        $ok = app(NotificationService::class)->sendTemplate($perRecipientTemplate, $context);

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
