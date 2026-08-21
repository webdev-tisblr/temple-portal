<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\DevoteeResource\Pages;
use App\Models\Devotee;
use App\Rules\ValidPhoneNumber;
use App\Support\PhoneNumber;
use Filament\Forms;
use Illuminate\Database\Eloquent\Builder;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DevoteeResource extends Resource
{

    protected static ?string $model = Devotee::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Booking & Donation Reports';

    protected static ?int $navigationSort = 50;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Personal Info')->schema([
                Forms\Components\TextInput::make('name')->required()->maxLength(255),
                // G16 (2026-08-09): phone is the devotee's UNIQUE OTP login
                // key. This field used to accept any free text, so an admin
                // creating a devotee could store "+91 98765 43210" (spaces
                // and prefix) — a value that never matches the normalised
                // login lookup and breaks PhoneNumber::forWhatsApp().
                // Normalise on dehydrate so what lands in the column is the
                // same canonical form SendOtpRequest/VerifyOtpRequest produce.
                Forms\Components\TextInput::make('phone')->tel()->required()->maxLength(20)
                    ->rule(new ValidPhoneNumber)
                    ->dehydrateStateUsing(fn (?string $state): ?string => PhoneNumber::normalize($state))
                    ->helperText('Indian numbers are stored as bare 10 digits (no +91).')
                    ->disabled(fn (?Devotee $record) => $record !== null),
                Forms\Components\TextInput::make('email')->email()->maxLength(255),
                Forms\Components\DatePicker::make('date_of_birth'),
                Forms\Components\Select::make('language')->options(['gu' => 'Gujarati', 'hi' => 'Hindi', 'en' => 'English'])->default('gu'),
                Forms\Components\Toggle::make('is_active')->default(true),
            ])->columns(2),

            Forms\Components\Section::make('Address')->schema([
                Forms\Components\Textarea::make('address')->rows(2),
                Forms\Components\TextInput::make('city')->maxLength(100),
                Forms\Components\TextInput::make('state')->default('Gujarat')->maxLength(100),
                Forms\Components\TextInput::make('pincode')->maxLength(10),
            ])->columns(2),

            Forms\Components\Section::make('PAN Info')->schema([
                Forms\Components\Placeholder::make('pan_status')
                    ->label('PAN on file')
                    ->content(fn (?Devotee $record) => $record?->pan_encrypted ? 'Yes (last 4: ' . $record->pan_last_four . ')' : 'No'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->limit(8)->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    // Signup only verifies a phone, so a row CAN legitimately
                    // exist with no name. Say so loudly rather than rendering
                    // a blank cell that reads like a UI glitch — this devotee
                    // gets no working WhatsApp message until it is filled in.
                    ->formatStateUsing(fn (?string $state) => filled(trim((string) $state)) ? $state : '⚠ No name')
                    ->color(fn (?string $state) => filled(trim((string) $state)) ? null : 'danger'),
                Tables\Columns\TextColumn::make('phone')->searchable(),
                Tables\Columns\TextColumn::make('city')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('language')->badge(),
                Tables\Columns\TextColumn::make('last_login_at')->dateTime('d M Y')->sortable()->label('Last Login'),
                Tables\Columns\TextColumn::make('created_at')->dateTime('d M Y')->sortable()->label('Registered'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('language')->options(['gu' => 'Gujarati', 'hi' => 'Hindi', 'en' => 'English']),
                Tables\Filters\TernaryFilter::make('is_active')->label('Active'),
                // The trust's worklist. A devotee with no name receives no
                // WhatsApp confirmations at all (Meta rejects a template
                // whose parameter is empty), so this filter is how you find
                // the people to ring up and ask.
                Tables\Filters\Filter::make('missing_name')
                    ->label('Missing name')
                    ->query(fn (Builder $query) => $query->whereRaw("COALESCE(TRIM(name), '') = ''")),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDevotees::route('/'),
            'create' => Pages\CreateDevotee::route('/create'),
            'edit' => Pages\EditDevotee::route('/{record}/edit'),
        ];
    }
}
