<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Support\TranslatableTabs;
use App\Models\SystemSetting;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;

/**
 * Website Display — the launch-marketing surfaces:
 *   • Top ribbon (announcement bar above the header)
 *   • Popup (announcement modal, once per visitor per day)
 * Both scheduled with start/end datetimes; stored as SystemSetting rows.
 */
class WebsiteDisplayPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';
    protected static ?string $navigationGroup = 'Content Management';
    protected static ?string $title = 'Website Display';
    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.website-display';

    public static function canAccess(): bool
    {
        return auth('admin')->user()?->can('page_WebsiteDisplayPage') ?? false;
    }

    public ?array $data = [];

    private const KEYS = [
        'ribbon_enabled', 'ribbon_text_gu', 'ribbon_text_hi', 'ribbon_text_en',
        'ribbon_link', 'ribbon_starts_at', 'ribbon_ends_at',
        'popup_enabled', 'popup_image', 'popup_title_gu', 'popup_title_hi', 'popup_title_en',
        'popup_body_gu', 'popup_body_hi', 'popup_body_en',
        'popup_cta_label_gu', 'popup_cta_label_hi', 'popup_cta_label_en',
        'popup_cta_url', 'popup_starts_at', 'popup_ends_at',
    ];

    public function mount(): void
    {
        $values = [];
        foreach (self::KEYS as $key) {
            $values[$key] = SystemSetting::getValue("site_{$key}", '');
        }
        $values['ribbon_enabled'] = $values['ribbon_enabled'] === '1';
        $values['popup_enabled'] = $values['popup_enabled'] === '1';

        $this->form->fill($values);
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Forms\Components\Section::make('Top Ribbon')
                ->description('A slim announcement bar above the site header. Dismissible by visitors.')
                ->schema([
                    Forms\Components\Toggle::make('ribbon_enabled')->label('Show ribbon'),
                    TranslatableTabs::make(fn (string $locale, string $label) => [
                        Forms\Components\TextInput::make("ribbon_text_{$locale}")->label("Text {$label}")->maxLength(300),
                    ], id: 'ribbon_translations'),
                    Forms\Components\TextInput::make('ribbon_link')->label('Link (optional)')->placeholder('/donate or https://…')->maxLength(500),
                    Forms\Components\DateTimePicker::make('ribbon_starts_at')->label('Show from')->native(false)->displayFormat('d M Y h:i A')->seconds(false),
                    Forms\Components\DateTimePicker::make('ribbon_ends_at')->label('Show until')->native(false)->displayFormat('d M Y h:i A')->seconds(false),
                ])->columns(2),

            Forms\Components\Section::make('Popup')
                ->description('Announcement modal shown once per visitor per day. Poster image, or title + text + button, or both.')
                ->schema([
                    Forms\Components\Toggle::make('popup_enabled')->label('Show popup'),
                    Forms\Components\FileUpload::make('popup_image')->label('Poster image (optional)')
                        ->image()->directory('site-popups')->maxSize(3072),
                    TranslatableTabs::make(fn (string $locale, string $label) => [
                        Forms\Components\TextInput::make("popup_title_{$locale}")->label("Title {$label}")->maxLength(300),
                        Forms\Components\Textarea::make("popup_body_{$locale}")->label("Text {$label}")->rows(2),
                        Forms\Components\TextInput::make("popup_cta_label_{$locale}")->label("Button label {$label}")->maxLength(100),
                    ], id: 'popup_translations'),
                    Forms\Components\TextInput::make('popup_cta_url')->label('Button link')->placeholder('/donate or https://…')->maxLength(500),
                    Forms\Components\DateTimePicker::make('popup_starts_at')->label('Show from')->native(false)->displayFormat('d M Y h:i A')->seconds(false),
                    Forms\Components\DateTimePicker::make('popup_ends_at')->label('Show until')->native(false)->displayFormat('d M Y h:i A')->seconds(false),
                ])->columns(2),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        foreach (self::KEYS as $key) {
            $value = $data[$key] ?? '';
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            SystemSetting::updateOrCreate(
                ['key' => "site_{$key}"],
                ['value' => (string) $value, 'group' => 'website', 'updated_at' => now()],
            );
        }

        Cache::forget('site.display.v1');

        Notification::make()->title('Website display settings saved')->success()->send();
    }
}
