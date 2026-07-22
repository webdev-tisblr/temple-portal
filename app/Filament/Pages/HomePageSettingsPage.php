<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Support\TranslatableTabs;
use App\Models\DonationCampaign;
use App\Models\Event;
use App\Models\SystemSetting;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;

/**
 * Home Page Settings — the single place to configure the public homepage:
 *   • Hero background (one image OR one looping video: uploaded MP4 or URL)
 *     with optional heading / subtitle overrides.
 *   • Featured cards — pick which campaigns / events (and the hall) appear
 *     in the hero-side highlight slider.
 *   • Top ribbon (announcement bar above the header).
 *   • Popup (announcement modal, once per visitor per day).
 *
 * Everything is stored as SystemSetting rows under the `site_*` key space,
 * so the existing ribbon/popup partials keep reading the same keys. This page
 * replaces the old "Hero Slider" resource and "Website Display" page.
 */
class HomePageSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-home-modern';
    protected static ?string $navigationGroup = 'Content Management';
    protected static ?string $title = 'Home Page Settings';
    protected static ?string $navigationLabel = 'Home Page Design';
    protected static ?int $navigationSort = 30;

    protected static string $view = 'filament.pages.home-page-settings';

    public static function canAccess(): bool
    {
        return auth('admin')->user()?->can('page_HomePageSettingsPage') ?? false;
    }

    public ?array $data = [];

    /** Keys stored as `site_{key}` SystemSetting rows. */
    private const KEYS = [
        // Hero background
        'hero_media_type', 'hero_image',
        'hero_video_type', 'hero_video_file', 'hero_video_url',
        'hero_video_audio', 'hero_video_controls',
        'hero_overlay',
        'hero_heading_gu', 'hero_heading_hi', 'hero_heading_en',
        'hero_sub_gu', 'hero_sub_hi', 'hero_sub_en',
        // Featured cards
        'card_campaign_ids', 'card_event_ids', 'card_show_hall', 'card_hall_id',
        // Top ribbon
        'ribbon_enabled', 'ribbon_text_gu', 'ribbon_text_hi', 'ribbon_text_en',
        'ribbon_link', 'ribbon_starts_at', 'ribbon_ends_at',
        // Popup
        'popup_enabled', 'popup_image', 'popup_title_gu', 'popup_title_hi', 'popup_title_en',
        'popup_body_gu', 'popup_body_hi', 'popup_body_en',
        'popup_cta_label_gu', 'popup_cta_label_hi', 'popup_cta_label_en',
        'popup_cta_url', 'popup_starts_at', 'popup_ends_at',
    ];

    /** Keys that hold a JSON-encoded array of IDs. */
    private const JSON_KEYS = ['card_campaign_ids', 'card_event_ids'];

    /** Keys that are booleans (toggles). */
    private const BOOL_KEYS = ['hero_video_audio', 'hero_video_controls', 'card_show_hall', 'ribbon_enabled', 'popup_enabled'];

    public function mount(): void
    {
        $values = [];
        foreach (self::KEYS as $key) {
            $raw = SystemSetting::getValue("site_{$key}", '');

            if (in_array($key, self::JSON_KEYS, true)) {
                $values[$key] = is_array($decoded = json_decode($raw ?: '[]', true)) ? $decoded : [];
            } elseif (in_array($key, self::BOOL_KEYS, true)) {
                // Unsaved show-hall defaults to on so existing homepages keep the hall card.
                $values[$key] = $key === 'card_show_hall' ? ($raw === '' || $raw === '1') : ($raw === '1');
            } else {
                $values[$key] = $raw;
            }
        }

        if (($values['hero_media_type'] ?? '') === '') {
            $values['hero_media_type'] = 'image';
        }
        if (($values['hero_video_type'] ?? '') === '') {
            $values['hero_video_type'] = 'upload';
        }

        $this->form->fill($values);
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Forms\Components\Section::make('Hero Background')
                ->description('The large banner at the top of the homepage. Use one image, or one short looping video.')
                ->schema([
                    Forms\Components\Radio::make('hero_media_type')
                        ->label('Background type')
                        ->options(['image' => 'Image', 'video' => 'Video'])
                        ->default('image')->inline()->live()->required(),

                    Forms\Components\FileUpload::make('hero_image')
                        ->label('Background image')
                        ->image()->directory('home-hero')->maxSize(6144)
                        ->helperText('Recommended 1920×1080 or wider. Also used as the video poster while it loads.')
                        ->visible(fn (Get $get) => $get('hero_media_type') === 'image'
                            || ($get('hero_media_type') === 'video' && $get('hero_video_type') === 'upload')),

                    Forms\Components\Radio::make('hero_video_type')
                        ->label('Video source')
                        ->options(['upload' => 'Upload a file (MP4)', 'url' => 'YouTube / Vimeo / MP4 link'])
                        ->default('upload')->inline()->live()
                        ->visible(fn (Get $get) => $get('hero_media_type') === 'video'),

                    Forms\Components\FileUpload::make('hero_video_file')
                        ->label('Background video (MP4)')
                        ->acceptedFileTypes(['video/mp4', 'video/webm'])
                        ->directory('home-hero-video')->maxSize(51200)
                        ->helperText('Keep it short and small (a few seconds, ideally under ~15 MB). Plays muted and loops.')
                        ->visible(fn (Get $get) => $get('hero_media_type') === 'video' && $get('hero_video_type') === 'upload'),

                    Forms\Components\TextInput::make('hero_video_url')
                        ->label('Video link')
                        ->placeholder('https://youtu.be/…  ·  https://vimeo.com/…  ·  https://…/clip.mp4')
                        ->maxLength(600)
                        ->visible(fn (Get $get) => $get('hero_media_type') === 'video' && $get('hero_video_type') === 'url'),

                    Forms\Components\Group::make([
                        Forms\Components\Toggle::make('hero_video_audio')
                            ->label('Play with sound')
                            ->helperText('Most browsers still start muted until the visitor clicks — turn on controls so they can unmute.'),
                        Forms\Components\Toggle::make('hero_video_controls')
                            ->label('Show play / pause controls'),
                    ])->visible(fn (Get $get) => $get('hero_media_type') === 'video'),

                    Forms\Components\TextInput::make('hero_overlay')
                        ->label('Darkening veil (%)')
                        ->numeric()->minValue(0)->maxValue(90)->default(40)
                        ->helperText('Higher = darker background, more readable white text.'),

                    TranslatableTabs::make(fn (string $locale, string $label) => [
                        Forms\Components\TextInput::make("hero_heading_{$locale}")->label("Heading {$label}")->maxLength(300)
                            ->placeholder('Leave blank to use the temple name'),
                        Forms\Components\Textarea::make("hero_sub_{$locale}")->label("Subtitle {$label}")->rows(2)
                            ->placeholder('Leave blank to use the default tagline'),
                    ], id: 'hero_text'),
                ])->columns(2),

            Forms\Components\Section::make('Featured Cards')
                ->description('The rotating cards beside the hero. Pick which campaigns and events to feature; the hall card is optional. Order = campaigns, then hall, then events. Leave the pickers empty to auto-show the latest.')
                ->schema([
                    Forms\Components\Select::make('card_campaign_ids')
                        ->label('Campaigns to feature')
                        ->multiple()->searchable()->preload()
                        // title is a localized accessor (title_gu/hi/en), not a
                        // real column — load models before plucking.
                        ->options(fn () => DonationCampaign::where('is_active', true)->orderByDesc('id')->get()->pluck('title', 'id')),
                    Forms\Components\Select::make('card_event_ids')
                        ->label('Events to feature')
                        ->multiple()->searchable()->preload()
                        ->options(fn () => Event::where('status', 'published')->orderBy('start_date')->get()->pluck('title', 'id')),
                    Forms\Components\Toggle::make('card_show_hall')
                        ->label('Show the community hall card')
                        ->live(),
                    Forms\Components\Select::make('card_hall_id')
                        ->label('Which hall to feature')
                        ->searchable()->preload()
                        // name is a locale-aware accessor (name_gu/hi/en), so
                        // load models before plucking — a raw pluck reads the
                        // legacy `name` column, blank on newer halls.
                        ->options(fn () => \App\Models\Hall::where('is_active', true)->get()->pluck('name', 'id'))
                        ->placeholder('Latest active hall')
                        ->helperText('The hall shown in the home card and hall band (its button links straight to that hall). Leave empty to use the most recent active hall.')
                        ->visible(fn (Get $get) => (bool) $get('card_show_hall')),
                ])->columns(2),

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

            if (in_array($key, self::JSON_KEYS, true)) {
                $value = json_encode(array_values(array_map('intval', is_array($value) ? $value : [])));
            } elseif (in_array($key, self::BOOL_KEYS, true)) {
                $value = $value ? '1' : '0';
            }

            SystemSetting::updateOrCreate(
                ['key' => "site_{$key}"],
                ['value' => (string) $value, 'group' => 'website', 'updated_at' => now()],
            );
        }

        // Bust every cache the homepage + ribbon/popup partials read from.
        Cache::forget('site.display.v1');
        Cache::forget('home.hero_config.v1');
        Cache::forget('home.hero_cards.v1');
        Cache::forget('home.hall.v1');

        Notification::make()->title('Home page settings saved')->success()->send();
    }
}
