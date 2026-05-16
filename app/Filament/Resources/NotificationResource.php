<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\NotificationResource\Pages;
use App\Jobs\SendPushNotification;
use App\Models\BlogPost;
use App\Models\Devotee;
use App\Models\DonationCampaign;
use App\Models\Event;
use App\Models\Notification;
use App\Models\Seva;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class NotificationResource extends Resource
{
    protected static ?string $model = Notification::class;
    protected static ?string $navigationIcon = 'heroicon-o-megaphone';
    protected static ?string $navigationGroup = 'Communication';
    protected static ?int $navigationSort = 1;
    protected static ?string $navigationLabel = 'Push Notifications';
    protected static ?string $modelLabel = 'Push notification';
    protected static ?string $pluralModelLabel = 'Push notifications';

    // Once a notification is sent or actively sending, it becomes read-only.
    public static function canEdit(Model $record): bool
    {
        return in_array($record->status, ['draft', 'scheduled', 'failed'], true);
    }

    public static function canDelete(Model $record): bool
    {
        return $record->status !== 'sending';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([

            Forms\Components\Section::make('Notification content')
                ->columns(1)
                ->schema([
                    Forms\Components\Tabs::make('locale')
                        ->tabs([
                            Forms\Components\Tabs\Tab::make('ગુજરાતી')
                                ->schema([
                                    Forms\Components\TextInput::make('title_gu')
                                        ->label('Title')
                                        ->required()
                                        ->maxLength(120),
                                    Forms\Components\Textarea::make('body_gu')
                                        ->label('Message')
                                        ->required()
                                        ->rows(3)
                                        ->maxLength(500),
                                ]),
                            Forms\Components\Tabs\Tab::make('हिन्दी')
                                ->schema([
                                    Forms\Components\TextInput::make('title_hi')->label('Title')->maxLength(120),
                                    Forms\Components\Textarea::make('body_hi')->label('Message')->rows(3)->maxLength(500),
                                ]),
                            Forms\Components\Tabs\Tab::make('English')
                                ->schema([
                                    Forms\Components\TextInput::make('title_en')->label('Title')->maxLength(120),
                                    Forms\Components\Textarea::make('body_en')->label('Message')->rows(3)->maxLength(500),
                                ]),
                        ])
                        ->columnSpanFull(),
                    Forms\Components\FileUpload::make('image_url')
                        ->label('Image (optional)')
                        ->image()
                        ->disk('r2')
                        ->visibility('public')
                        ->directory('notifications')
                        ->maxSize(2048)
                        ->helperText('JPG or PNG, max 2 MB. Renders alongside the title/body on Android. iOS needs a Notification Service Extension to display the image — see FIREBASE_SETUP.md.'),
                ]),

            Forms\Components\Section::make('Open in app on tap')
                ->description("Choose what the devotee sees when they tap the notification. Leave blank to just open the home screen.")
                ->schema([
                    Forms\Components\Select::make('intent')
                        ->label('Open')
                        ->options(self::intentOptions())
                        ->placeholder('— Just open the app (home) —')
                        ->live()
                        ->afterStateUpdated(function (Forms\Set $set) {
                            // Reset both the synthetic UI picker and the
                            // persisted JSON whenever the intent changes,
                            // so stale IDs don't leak across intents.
                            $set('intent_target', null);
                            $set('intent_params', null);
                        })
                        ->columnSpanFull(),

                    // One shared picker. Its label, options, and required-ness
                    // all derive from the current intent. Dehydrated false so
                    // it doesn't land in the DB directly — the Create/Edit
                    // pages hydrate it from / store it back into intent_params.
                    Forms\Components\Select::make('intent_target')
                        ->label(fn (Get $get): string => match ($get('intent')) {
                            'seva-detail' => 'Seva',
                            'campaign-detail' => 'Campaign',
                            'event-detail' => 'Event',
                            'blog-detail' => 'Blog post',
                            default => 'Target',
                        })
                        ->options(function (Get $get): array {
                            return match ($get('intent')) {
                                'seva-detail' => Seva::query()->orderBy('name_gu')->pluck('name_gu', 'id')->all(),
                                'campaign-detail' => DonationCampaign::query()->orderBy('title_gu')->pluck('title_gu', 'id')->all(),
                                'event-detail' => Event::query()->orderByDesc('start_date')->pluck('title_gu', 'id')->all(),
                                'blog-detail' => BlogPost::query()->orderBy('title_gu')->pluck('title_gu', 'slug')->all(),
                                default => [],
                            };
                        })
                        ->searchable()
                        ->preload()
                        ->dehydrated(false)
                        ->required(fn (Get $get): bool => in_array(
                            $get('intent'),
                            ['seva-detail', 'campaign-detail', 'event-detail', 'blog-detail'],
                            true,
                        ))
                        ->visible(fn (Get $get): bool => in_array(
                            $get('intent'),
                            ['seva-detail', 'campaign-detail', 'event-detail', 'blog-detail'],
                            true,
                        )),
                ]),

            Forms\Components\Section::make('Audience')
                ->schema([
                    Forms\Components\Select::make('segment')
                        ->label('Who receives this')
                        ->options([
                            'all' => 'Everyone with the app (logged in or browsing)',
                            'donors' => 'Donors only',
                            'active_users' => 'Active users (logged in within 30 days)',
                            'inactive_users' => 'Inactive users (no login in 30+ days)',
                            'birthday_today' => 'Devotees with birthday today',
                            'custom' => 'Specific devotees',
                        ])
                        ->helperText('"Everyone" includes devotees who installed the app but haven\'t logged in yet. The other segments only target logged-in devotees.')
                        ->required()
                        ->default('all')
                        ->live(),

                    Forms\Components\Select::make('custom_filter.devotee_ids')
                        ->label('Pick devotees')
                        ->multiple()
                        ->searchable()
                        ->preload(false)
                        ->getSearchResultsUsing(function (string $search): array {
                            return Devotee::query()
                                ->where(function ($q) use ($search) {
                                    $q->where('name', 'like', "%{$search}%")
                                        ->orWhere('phone', 'like', "%{$search}%");
                                })
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn (Devotee $d) => [$d->id => "{$d->name} ({$d->phone})"])
                                ->all();
                        })
                        ->getOptionLabelsUsing(function (array $values): array {
                            return Devotee::query()
                                ->whereIn('id', $values)
                                ->get()
                                ->mapWithKeys(fn (Devotee $d) => [$d->id => "{$d->name} ({$d->phone})"])
                                ->all();
                        })
                        ->visible(fn (Get $get) => $get('segment') === 'custom')
                        ->required(fn (Get $get) => $get('segment') === 'custom')
                        ->helperText('Type a name or phone number to search.'),
                ]),

            Forms\Components\Section::make('When to send')
                ->schema([
                    Forms\Components\Radio::make('send_mode')
                        ->options([
                            'now' => 'Send right now',
                            'schedule' => 'Schedule for later',
                        ])
                        ->default('now')
                        ->inline()
                        ->dehydrated(false)
                        ->live(),

                    // Browser-native datetime-local picker:
                    //   • desktop Chrome/Safari + iOS + Android all render
                    //     12-hour AM/PM in en-US locale, mobile gets the OS
                    //     native scrollers
                    //   • Filament's own non-native picker only exposes a
                    //     24-hour hour spinner (vendor/filament/forms/.../
                    //     date-time-picker.blade.php hard-codes max=23) — no
                    //     AM/PM toggle, and any value < min snaps back to 0
                    //   • The native input enforces step=60 against the
                    //     `min` value's SECONDS offset. minDate(now()) has
                    //     live seconds (eg 10:06:39) so 10:07:00 became
                    //     invalid — the "nearest valid values are HH:MM:39"
                    //     error from the original bug. addMinute()
                    //     ->startOfMinute() gives a clean minute-aligned
                    //     min that always lies in the FUTURE so the picker
                    //     doesn't reject the moment the page loads.
                    Forms\Components\DateTimePicker::make('scheduled_at')
                        ->label('Scheduled time')
                        ->seconds(false)
                        ->minutesStep(1)
                        ->minDate(fn () => now()->addMinute()->startOfMinute())
                        ->visible(fn (Get $get) => $get('send_mode') === 'schedule')
                        ->required(fn (Get $get) => $get('send_mode') === 'schedule')
                        ->helperText('Pick the date and time. The dispatcher checks every minute, so delivery fires within ~1 min of this time.'),
                ]),

            Forms\Components\Section::make('Delivery')
                ->hiddenOn('create')
                ->schema([
                    Forms\Components\Placeholder::make('status_view')
                        ->label('Status')
                        ->content(fn (?Model $record) => $record?->status ?? '—'),
                    Forms\Components\Placeholder::make('total_recipients_view')
                        ->label('Total recipients')
                        ->content(fn (?Model $record) => (string) ($record?->total_recipients ?? 0)),
                    Forms\Components\Placeholder::make('delivered_count_view')
                        ->label('Delivered')
                        ->content(fn (?Model $record) => (string) ($record?->delivered_count ?? 0)),
                    Forms\Components\Placeholder::make('sent_at_view')
                        ->label('Sent at')
                        ->content(fn (?Model $record) => $record?->sent_at?->format('d M Y, H:i') ?? '—'),
                ])
                ->columns(4),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image_url')
                    ->label('')
                    ->disk('r2')
                    ->visibility('public')
                    ->circular()
                    ->size(40)
                    ->defaultImageUrl(asset('images/shree-pataliya-hanumanji-logo.png')),
                Tables\Columns\TextColumn::make('title_gu')
                    ->label('Title')
                    ->searchable()
                    ->limit(40)
                    ->wrap(),
                Tables\Columns\TextColumn::make('intent')
                    ->label('Opens')
                    ->badge()
                    ->color('gray')
                    ->placeholder('home'),
                Tables\Columns\TextColumn::make('segment')
                    ->badge()
                    ->color('info'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'sent' => 'success',
                        'scheduled' => 'info',
                        'sending' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('scheduled_at')
                    ->dateTime('d M, H:i')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('sent_at')
                    ->dateTime('d M, H:i')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('delivered_count')
                    ->label('Delivered / Total')
                    ->formatStateUsing(fn ($state, Model $record) => "{$state} / {$record->total_recipients}"),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'draft' => 'Draft',
                    'scheduled' => 'Scheduled',
                    'sending' => 'Sending',
                    'sent' => 'Sent',
                    'failed' => 'Failed',
                ]),
                Tables\Filters\SelectFilter::make('segment')->options([
                    'all' => 'All',
                    'donors' => 'Donors',
                    'active_users' => 'Active',
                    'inactive_users' => 'Inactive',
                    'birthday_today' => 'Birthday today',
                    'custom' => 'Custom',
                ]),
            ])
            ->actions([
                // Send-now works on every status except 'sending' (mid-flight).
                // For draft/scheduled/failed we dispatch the existing row in
                // place. For 'sent' we clone the row first so the original
                // sent-record + its delivery counts stay intact as an audit
                // trail; the resend lives as a new row.
                Tables\Actions\Action::make('send_now')
                    ->label('Send now')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Send notification now')
                    ->modalDescription(function (Model $record): string {
                        $verb = $record->status === 'sent' ? 'resend' : 'dispatch';
                        return "This will {$verb} \"{$record->title_gu}\" to every targeted device immediately. Continue?";
                    })
                    ->modalSubmitActionLabel('Send now')
                    ->modalCancelActionLabel('Cancel')
                    ->visible(fn (Model $record) => $record->status !== 'sending')
                    ->action(function (Model $record) {
                        if ($record->status === 'sent') {
                            // Clone so the original audit trail stays intact.
                            $target = $record->replicate([
                                'sent_at',
                                'total_recipients',
                                'delivered_count',
                                'opened_count',
                            ]);
                            $target->status = 'sending';
                            $target->scheduled_at = null;
                            $target->created_by = auth()->id();
                            $target->save();
                        } else {
                            $record->update([
                                'status' => 'sending',
                                'scheduled_at' => null,
                            ]);
                            $target = $record;
                        }

                        SendPushNotification::dispatch($target);

                        FilamentNotification::make()
                            ->title('Notification dispatched')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // Block bulk-delete on rows currently being dispatched —
                    // deleting mid-send would orphan the queued job.
                    Tables\Actions\DeleteBulkAction::make()
                        ->before(function ($records) {
                            $inFlight = $records->filter(fn (Model $r) => $r->status === 'sending');
                            if ($inFlight->isNotEmpty()) {
                                FilamentNotification::make()
                                    ->title('Skipped rows currently sending')
                                    ->body("{$inFlight->count()} row(s) are mid-dispatch and were not deleted.")
                                    ->warning()
                                    ->send();
                            }
                        })
                        ->action(function ($records) {
                            $deletable = $records->filter(fn (Model $r) => $r->status !== 'sending');
                            $deletable->each->delete();

                            FilamentNotification::make()
                                ->title("Deleted {$deletable->count()} notification(s)")
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotifications::route('/'),
            'create' => Pages\CreateNotification::route('/create'),
            'edit' => Pages\EditNotification::route('/{record}/edit'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function intentOptions(): array
    {
        return [
            'home' => 'Home',
            'donate' => 'Donate (free amount)',
            'campaigns' => 'Campaigns list',
            'campaign-detail' => 'Specific campaign',
            'seva-detail' => 'Specific seva',
            'events' => 'Events list',
            'event-detail' => 'Specific event',
            'blog' => 'Blog',
            'blog-detail' => 'Specific blog post',
            'halls' => 'Halls list',
            'store' => 'Store',
            'profile' => 'Profile',
            'contact' => 'Contact us',
            'inbox' => 'Notification inbox',
        ];
    }
}
