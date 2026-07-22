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
    protected static ?string $navigationGroup = 'Portal Setup';
    protected static ?string $title = 'Darshan Timings';
    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.pages.darshan-timings';

    /**
     * Gate navigation + direct URL access through the Shield-seeded
     * `page_DarshanTimingsPage` permission. Super admin auto-passes via
     * Gate::before in AuthServiceProvider.
     */
    public static function canAccess(): bool
    {
        return auth('admin')->user()?->can('page_DarshanTimingsPage') ?? false;
    }

    public ?array $regular = [];
    public ?array $special = [];
    public ?string $temple_rules_gu = '';
    public ?string $temple_rules_hi = '';
    public ?string $temple_rules_en = '';

    public function mount(): void
    {
        // Regular = Sunday–Friday; Special = Saturday. (The legacy 'sunday'
        // day_type is retired — regular now covers Sunday.)
        $regularTiming = DarshanTiming::where('day_type', 'regular')->where('is_active', true)->first();
        $specialTiming = DarshanTiming::where('day_type', 'special')->where('is_active', true)->first();

        $this->form->fill([
            'regular' => $regularTiming ? $regularTiming->toArray() : [],
            'special' => $specialTiming ? $specialTiming->toArray() : [],
            // Fall back to the legacy single-language key for Gujarati so
            // existing rules aren't lost on first open after the upgrade.
            'temple_rules_gu' => SystemSetting::getValue('temple_rules_gu', SystemSetting::getValue('temple_rules', '')),
            'temple_rules_hi' => SystemSetting::getValue('temple_rules_hi', ''),
            'temple_rules_en' => SystemSetting::getValue('temple_rules_en', ''),
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

        $rulesEditor = fn (string $name) => Forms\Components\RichEditor::make($name)
            ->label('')
            ->placeholder('Enter temple rules, guidelines, dress code, photography policy, etc.')
            ->toolbarButtons([
                'bold', 'italic', 'underline', 'strike',
                'h2', 'h3',
                'bulletList', 'orderedList',
                'link', 'blockquote',
                'undo', 'redo',
            ]);

        return $form->schema([
            Forms\Components\Section::make('Regular Timings (Sunday – Friday)')
                ->schema($timingFields('regular'))
                ->columns(3),
            Forms\Components\Section::make('Special Timings (Saturday)')
                ->schema($timingFields('special'))
                ->columns(3),
            Forms\Components\Section::make('Temple Rules & Regulations')
                ->icon('heroicon-o-document-text')
                ->description('Shown on the Darshan page under the timings, in each visitor\'s language.')
                ->schema([
                    Forms\Components\Tabs::make('temple_rules')
                        ->tabs([
                            Forms\Components\Tabs\Tab::make('ગુજરાતી')->schema([$rulesEditor('temple_rules_gu')]),
                            Forms\Components\Tabs\Tab::make('हिन्दी')->schema([$rulesEditor('temple_rules_hi')]),
                            Forms\Components\Tabs\Tab::make('English')->schema([$rulesEditor('temple_rules_en')]),
                        ]),
                ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        foreach (['regular', 'special'] as $dayType) {
            if (!empty($data[$dayType])) {
                DarshanTiming::updateOrCreate(
                    ['day_type' => $dayType],
                    array_merge($data[$dayType], ['day_type' => $dayType, 'is_active' => true])
                );
            }
        }

        // Retire the legacy Sunday row — regular now covers Sunday–Friday.
        DarshanTiming::where('day_type', 'sunday')->update(['is_active' => false]);

        // Temple rules per language + keep the legacy key (gu) populated for
        // any consumer still reading `temple_rules`.
        foreach (['gu', 'hi', 'en'] as $lang) {
            SystemSetting::updateOrCreate(
                ['key' => "temple_rules_{$lang}"],
                ['value' => $data["temple_rules_{$lang}"] ?? '', 'group' => 'general', 'updated_at' => now()]
            );
        }
        SystemSetting::updateOrCreate(
            ['key' => 'temple_rules'],
            ['value' => $data['temple_rules_gu'] ?? '', 'group' => 'general', 'updated_at' => now()]
        );

        // Invalidate every cached view of the timings.
        Cache::forget('darshan_timings');
        Cache::forget('darshan_timings_all');
        Cache::forget('header_strip_timing');
        Cache::forget('home.today_timing.v1'); // home badge/ticker timing row
        \App\Support\LocalizedCache::forget('darshan_timings.active');

        Notification::make()->title('Darshan timings & rules updated')->success()->send();
    }
}
