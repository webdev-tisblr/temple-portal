<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SevaReminderRuleResource\Pages;
use App\Filament\Support\ReminderRuleFields;
use App\Models\Seva;
use App\Models\SevaReminderRule;
use App\Services\SevaReminderScheduler;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Central home for seva reminder configuration. Rules with no seva are
 * the GLOBAL defaults (every seva whose reminder mode is "global");
 * rules bound to a seva only apply when that seva's mode is "custom".
 * The same rules are also editable inline on the Edit Seva page.
 */
class SevaReminderRuleResource extends Resource
{
    protected static ?string $model = SevaReminderRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';
    protected static ?string $navigationGroup = 'Communication';
    protected static ?string $navigationLabel = 'Seva Reminders';
    protected static ?string $modelLabel = 'Seva Reminder Rule';
    protected static ?int $navigationSort = 3;

    /**
     * Hidden from the menu by user preference — reminder rules are managed
     * inline on the Edit Seva page ("Reminders" section). This resource
     * stays routable (/admin/seva-reminder-rules) as the only place to
     * manage GLOBAL (all-seva, seva_id NULL) rules if ever needed.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Scope')->schema([
                Forms\Components\Select::make('seva_id')
                    ->label('Applies to')
                    ->options(fn () => Seva::query()->orderBy('sort_order')->pluck('name_gu', 'id')->all())
                    ->placeholder('All sevas (global default)')
                    ->helperText('Leave empty for a global default rule. A seva uses global rules unless its own "Reminders" section is set to Custom.')
                    ->searchable(),
            ]),
            Forms\Components\Section::make('Rule')->schema(ReminderRuleFields::schema())->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('seva.name_gu')
                    ->label('Scope')
                    ->placeholder('Global — all sevas')
                    ->sortable(),
                Tables\Columns\TextColumn::make('offset_minutes')
                    ->label('When')
                    ->formatStateUsing(fn (int $state): string => SevaReminderScheduler::humanLabel($state) . ' before')
                    ->sortable(),
                Tables\Columns\TextColumn::make('recipient_type')
                    ->label('Recipient')
                    ->badge()
                    ->formatStateUsing(fn (string $state, SevaReminderRule $record): string => match ($state) {
                        SevaReminderRule::RECIPIENT_DEVOTEE => 'Devotee',
                        SevaReminderRule::RECIPIENT_ADMIN_ROLE => 'Role: ' . ($record->recipient_value ?: '—'),
                        SevaReminderRule::RECIPIENT_ASSIGNEE => 'Seva assignee',
                        SevaReminderRule::RECIPIENT_CUSTOM_PHONE => 'Phone: ' . ($record->recipient_value ?: '—'),
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('channel')->badge(),
                Tables\Columns\IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->defaultSort('seva_id')
            ->filters([
                Tables\Filters\TernaryFilter::make('seva_id')
                    ->label('Scope')
                    ->nullable()
                    ->trueLabel('Per-seva rules')
                    ->falseLabel('Global rules')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('seva_id'),
                        false: fn ($query) => $query->whereNull('seva_id'),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSevaReminderRules::route('/'),
            'create' => Pages\CreateSevaReminderRule::route('/create'),
            'edit' => Pages\EditSevaReminderRule::route('/{record}/edit'),
        ];
    }
}
