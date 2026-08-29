<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ContactSubmissionResource\Pages;
use App\Models\ContactSubmission;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ContactSubmissionResource extends Resource
{

    protected static ?string $model = ContactSubmission::class;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationGroup = 'Communication';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Contact Messages';

    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::where('is_read', false)->count();
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Sender')
                ->description('Name and phone are copied from the devotee\'s profile at submission time — the form no longer lets anyone type them.')
                ->schema([
                    Forms\Components\TextInput::make('name')->disabled(),
                    Forms\Components\TextInput::make('phone')->disabled(),
                    Forms\Components\TextInput::make('email')->disabled(),
                    Forms\Components\TextInput::make('ip_address')->label('IP')->disabled(),
                    // Placeholder, not a relation TextInput — dot-notation on
                    // a related model does not hydrate on an Edit page.
                    Forms\Components\Placeholder::make('devotee_link')
                        ->label('Devotee account')
                        ->content(fn (?ContactSubmission $record): string => $record?->devotee
                            ? $record->devotee->name.' ('.$record->devotee->phone.')'
                            : '— submitted before login was required —'),
                ])->columns(2),
            Forms\Components\Section::make('Message')->schema([
                Forms\Components\TextInput::make('category')
                    ->formatStateUsing(fn ($state): string => $state instanceof \App\Enums\ContactCategory
                        ? $state->label()
                        : (string) $state)
                    ->disabled(),
                Forms\Components\TextInput::make('subject')->disabled(),
                Forms\Components\Textarea::make('message')->rows(6)->disabled(),
            ]),
            // The two-way half (2026-08-29). The transcript is read-only here;
            // replying goes through the Reply action so every turn lands in
            // ContactThreadService and the devotee actually gets told.
            Forms\Components\Section::make('Conversation')
                ->description('Everything said in this thread, oldest first. Use the Reply button above to answer — the devotee sees it in the app.')
                ->schema([
                    Forms\Components\Placeholder::make('thread')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->content(fn (?ContactSubmission $record) => view(
                            'filament.components.contact-thread',
                            ['record' => $record?->loadMissing('messages.adminUser')],
                        )),
                ]),

            Forms\Components\Section::make('Status')->schema([
                Forms\Components\Toggle::make('is_read')->label('Marked as read'),
                Forms\Components\DateTimePicker::make('read_at')->label('Read at')->disabled(),
                Forms\Components\DateTimePicker::make('created_at')->label('Received at')->disabled(),
            ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\IconColumn::make('is_read')
                    ->boolean()
                    ->label('Read')
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-envelope')
                    ->trueColor('success')
                    ->falseColor('warning'),
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('phone')->searchable(),
                // "Which of these are suggestions?" is the question this
                // inbox gets asked most, so it earns a column and a filter.
                Tables\Columns\TextColumn::make('category')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof \App\Enums\ContactCategory
                        ? $state->label()
                        : (string) $state)
                    ->color(fn ($state): string => match ($state instanceof \App\Enums\ContactCategory ? $state->value : $state) {
                        'suggestion' => 'success',
                        'complaint' => 'danger',
                        'feedback' => 'info',
                        'seva_request' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('subject')->limit(40)->searchable(),
                Tables\Columns\TextColumn::make('messages_count')
                    ->counts('messages')
                    ->label('Replies')
                    ->badge()
                    ->color(fn ($state): string => (int) $state > 0 ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->label('Received'),
                Tables\Columns\TextColumn::make('last_message_at')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->label('Last activity')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_read')->label('Read status'),
                Tables\Filters\SelectFilter::make('category')
                    ->label('Type')
                    ->options(fn (): array => \App\Enums\ContactCategory::options()),
            ])
            ->actions([
                Tables\Actions\Action::make('markRead')
                    ->label('Mark read')
                    ->icon('heroicon-o-check-circle')
                    // G15 (2026-08-09): state + permission in ONE closure.
                    // `accountant`/`volunteer` never get update on this
                    // resource, so read-state was mutable by view-only roles.
                    ->visible(fn (ContactSubmission $record): bool => ! $record->is_read
                        && (auth('admin')->user()?->can('update_contact::submission') ?? false))
                    ->action(function (ContactSubmission $record) {
                        $record->update(['is_read' => true, 'read_at' => now()]);
                    }),
                static::replyAction(),
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('markAllRead')
                        ->label('Mark as read')
                        ->icon('heroicon-o-check-circle')
                        // G15 (2026-08-09): custom bulk actions are not
                        // auto-authorized — see G17 on SevaBookingResource.
                        ->visible(fn (): bool => auth('admin')->user()?->can('update_contact::submission') ?? false)
                        ->action(function ($records) {
                            foreach ($records as $r) {
                                if (!$r->is_read) {
                                    $r->update(['is_read' => true, 'read_at' => now()]);
                                }
                            }
                        }),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Answer a devotee. Shared by the table row and the View page header so
     * the two cannot drift in what they do or who is allowed to do it.
     *
     * `update_contact::submission` is the gate: replying on the trust's behalf
     * is not a view-only act, and the same permission already guards marking
     * a message read.
     */
    public static function replyAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('reply')
            ->label('Reply')
            ->icon('heroicon-o-chat-bubble-left-right')
            ->color('primary')
            ->modalHeading(fn (ContactSubmission $record): string => 'Reply to '.($record->name ?: 'devotee'))
            ->modalDescription('The devotee sees this in the app under Messages. If a "Contact — trust replied" notification template is enabled, they are told about it too.')
            ->modalSubmitActionLabel('Send reply')
            ->visible(fn (ContactSubmission $record): bool => $record->devotee_id !== null
                && (auth('admin')->user()?->can('update_contact::submission') ?? false))
            ->form([
                Forms\Components\Placeholder::make('original')
                    ->label('Their message')
                    ->content(fn (ContactSubmission $record): string => $record->message),
                Forms\Components\Textarea::make('body')
                    ->hiddenLabel()
                    ->placeholder('Write your reply…')
                    ->rows(6)
                    ->required()
                    ->maxLength(2000),
            ])
            ->action(function (ContactSubmission $record, array $data): void {
                app(\App\Services\ContactThreadService::class)->replyAsAdmin(
                    $record,
                    (string) $data['body'],
                    auth('admin')->user(),
                );

                \Filament\Notifications\Notification::make()
                    ->success()
                    ->title('Reply sent')
                    ->body('It is now visible to the devotee in the app.')
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContactSubmissions::route('/'),
            'view' => Pages\ViewContactSubmission::route('/{record}'),
            'edit' => Pages\EditContactSubmission::route('/{record}/edit'),
        ];
    }
}
