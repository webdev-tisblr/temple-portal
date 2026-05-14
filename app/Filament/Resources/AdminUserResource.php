<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AdminUserResource\Pages;
use App\Models\AdminUser;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;

class AdminUserResource extends Resource
{
    protected static ?string $model = AdminUser::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('email')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),

                // Password is only required on create. On edit, blank keeps
                // the existing hash — `dehydrated` strips the field from the
                // save payload when empty so the model's `hashed` cast isn't
                // re-applied to an empty string.
                Forms\Components\TextInput::make('password')
                    ->password()
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->maxLength(255)
                    ->helperText(fn (string $operation) => $operation === 'edit' ? 'Leave blank to keep the current password.' : null),

                Forms\Components\TextInput::make('phone')
                    ->tel()
                    ->maxLength(15),

                // Multi-role select. Spatie supports many-to-many on
                // model_has_roles natively; the relationship() call writes
                // through to that pivot. Pre-loaded options to keep the
                // dropdown snappy on temples with few admins.
                Forms\Components\Select::make('roles')
                    ->relationship('roles', 'name', fn (Builder $query) => $query->where('guard_name', 'admin'))
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->helperText('A user inherits every permission from every assigned role. Manage roles in the Roles resource.')
                    ->columnSpanFull(),

                Forms\Components\Toggle::make('is_active')
                    ->default(true)
                    ->helperText('Deactivated users keep their data but cannot log in.')
                    // Self-lockout guard: editing your own record can't flip
                    // is_active off. Pair with canDelete() below — the
                    // currently-logged-in admin can never strand themselves.
                    ->disabled(fn (?AdminUser $record) => $record !== null && auth('admin')->id() === $record->id),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('roles.name')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'super_admin' => 'danger',
                        'trustee' => 'warning',
                        'accountant' => 'info',
                        'pujari' => 'success',
                        default => 'gray',
                    })
                    ->label('Roles'),

                Tables\Columns\TextColumn::make('permissions_count')
                    ->label('Direct perms')
                    ->state(fn (AdminUser $record): int => $record->getDirectPermissions()->count())
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('last_login_at')
                    ->dateTime('d M Y H:i')
                    ->label('Last Login')
                    ->placeholder('Never')
                    ->sortable(),

                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Active')
                    // Can't toggle off your own account inline.
                    ->disabled(fn (AdminUser $record): bool => auth('admin')->id() === $record->id),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('roles')
                    ->relationship('roles', 'name', fn (Builder $query) => $query->where('guard_name', 'admin'))
                    ->multiple()
                    ->preload(),

                Tables\Filters\TernaryFilter::make('is_active'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Self-protection: a logged-in admin cannot delete their own row.
     * Avoids the worst-case where the only super admin clicks delete on
     * themselves and locks the platform out.
     */
    public static function canDelete($record): bool
    {
        if (auth('admin')->id() === $record?->id) {
            return false;
        }

        return parent::canDelete($record);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAdminUsers::route('/'),
            'create' => Pages\CreateAdminUser::route('/create'),
            'edit' => Pages\EditAdminUser::route('/{record}/edit'),
        ];
    }
}
