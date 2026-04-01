<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\SystemSetting;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SystemSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationGroup = 'System';
    protected static ?string $title = 'System Settings';
    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.system-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $settings = SystemSetting::all()->pluck('value', 'key')->toArray();
        $this->form->fill($settings);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Trust Details')->schema([
                Forms\Components\TextInput::make('trust_name')->label('Trust Name')->required(),
                Forms\Components\Textarea::make('trust_address')->label('Address')->rows(2),
                Forms\Components\TextInput::make('trust_pan')->label('Trust PAN'),
                Forms\Components\TextInput::make('trust_phone')->label('Phone'),
                Forms\Components\TextInput::make('trust_email')->label('Email'),
            ])->columns(2),

            Forms\Components\Section::make('80G Details')->schema([
                Forms\Components\TextInput::make('trust_80g_reg_no')->label('80G Registration No.'),
                Forms\Components\TextInput::make('trust_80g_validity')->label('80G Validity Period'),
                Forms\Components\TextInput::make('receipt_prefix')->label('Receipt Prefix')->default('SPHST/80G'),
            ])->columns(2),

            Forms\Components\Section::make('General')->schema([
                Forms\Components\TextInput::make('youtube_live_url')->label('YouTube Live URL')->url(),
                Forms\Components\TextInput::make('youtube_channel_id')->label('YouTube Channel ID'),
                Forms\Components\Select::make('default_language')->label('Default Language')
                    ->options(['gu' => 'Gujarati', 'hi' => 'Hindi', 'en' => 'English'])->default('gu'),
            ])->columns(2),
        ])->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        foreach ($data as $key => $value) {
            SystemSetting::updateOrCreate(
                ['key' => $key],
                ['value' => $value ?? '', 'updated_at' => now()]
            );
        }

        Notification::make()->title('Settings saved successfully')->success()->send();
    }
}
