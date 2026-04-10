<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\DarshanTiming;
use App\Models\SystemSetting;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;

class DarshanTimingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-clock';
    protected static ?string $navigationGroup = 'Temple Management';
    protected static ?string $title = 'Darshan Timings';
    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.darshan-timings';

    public ?array $regular = [];
    public ?array $sunday = [];
    public ?string $temple_rules = '';

    public function mount(): void
    {
        $regularTiming = DarshanTiming::where('day_type', 'regular')->where('is_active', true)->first();
        $sundayTiming = DarshanTiming::where('day_type', 'sunday')->where('is_active', true)->first();

        $this->form->fill([
            'regular' => $regularTiming ? $regularTiming->toArray() : [],
            'sunday' => $sundayTiming ? $sundayTiming->toArray() : [],
            'temple_rules' => SystemSetting::getValue('temple_rules', ''),
        ]);
    }

    public function form(Form $form): Form
    {
        $timingFields = fn (string $prefix) => [
            Forms\Components\TimePicker::make("{$prefix}.morning_open")->label('Morning Open')->required()->seconds(false),
            Forms\Components\TimePicker::make("{$prefix}.morning_close")->label('Morning Close')->required()->seconds(false),
            Forms\Components\TimePicker::make("{$prefix}.evening_open")->label('Evening Open')->required()->seconds(false),
            Forms\Components\TimePicker::make("{$prefix}.evening_close")->label('Evening Close')->required()->seconds(false),
            Forms\Components\TimePicker::make("{$prefix}.aarti_morning")->label('Morning Aarti')->seconds(false),
            Forms\Components\TimePicker::make("{$prefix}.aarti_evening")->label('Evening Aarti')->seconds(false),
        ];

        return $form->schema([
            Forms\Components\Section::make('Regular Day Timings')
                ->schema($timingFields('regular'))
                ->columns(3),
            Forms\Components\Section::make('Sunday Timings')
                ->schema($timingFields('sunday'))
                ->columns(3),
            Forms\Components\Section::make('Temple Rules & Regulations')
                ->icon('heroicon-o-document-text')
                ->description('This content will be displayed on the Darshan page under temple timings.')
                ->schema([
                    Forms\Components\RichEditor::make('temple_rules')
                        ->label('')
                        ->placeholder('Enter temple rules, guidelines, dress code, photography policy, etc.')
                        ->toolbarButtons([
                            'bold', 'italic', 'underline', 'strike',
                            'h2', 'h3',
                            'bulletList', 'orderedList',
                            'link', 'blockquote',
                            'undo', 'redo',
                        ]),
                ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        foreach (['regular', 'sunday'] as $dayType) {
            if (!empty($data[$dayType])) {
                DarshanTiming::updateOrCreate(
                    ['day_type' => $dayType],
                    array_merge($data[$dayType], ['is_active' => true])
                );
            }
        }

        // Save temple rules
        SystemSetting::updateOrCreate(
            ['key' => 'temple_rules'],
            ['value' => $data['temple_rules'] ?? '', 'group' => 'general', 'updated_at' => now()]
        );

        Cache::forget('darshan_timings');

        Notification::make()->title('Darshan timings & rules updated')->success()->send();
    }
}
