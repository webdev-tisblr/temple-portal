<?php

declare(strict_types=1);

namespace App\Filament\Resources\DailyDarshanPhotoResource\Pages;

use App\Filament\Resources\DailyDarshanPhotoResource;
use App\Models\SystemSetting;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListDailyDarshanPhotos extends ListRecords
{
    protected static string $resource = DailyDarshanPhotoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Live-darshan configuration lives HERE with the darshan
            // content (moved out of System Settings → General). Same
            // underlying SystemSetting keys — the API/app/web read
            // them unchanged.
            Actions\Action::make('live_settings')
                ->label('Live darshan settings')
                ->icon('heroicon-o-video-camera')
                ->color('gray')
                // G11 (2026-08-09): this writes temple_system_settings rows
                // (youtube_live_url, youtube_channel_id,
                // live_darshan_placeholder_image) and uploads to R2. `staff`
                // and `volunteer` hold view_any_daily::darshan::photo, so
                // content-level permissions were escalating into system
                // configuration. The keys moved here for editing convenience
                // but they are still System Settings — gate them as such.
                ->visible(fn (): bool => auth('admin')->user()?->can('page_SystemSettings') ?? false)
                ->modalHeading('Live Darshan — YouTube')
                ->modalDescription('The live stream shown in the app and on the website darshan page. The stream plays only during darshan hours (per the Darshan Timings); outside them visitors see the offline image below with the next-darshan time.')
                ->fillForm(fn () => [
                    'youtube_live_url' => SystemSetting::getValue('youtube_live_url', ''),
                    'youtube_channel_id' => SystemSetting::getValue('youtube_channel_id', ''),
                    'youtube_api_key' => SystemSetting::getValue('youtube_api_key', ''),
                    'live_darshan_placeholder_image' => SystemSetting::getValue('live_darshan_placeholder_image', '') ?: null,
                ])
                ->form([
                    Forms\Components\TextInput::make('youtube_live_url')
                        ->label('YouTube Live URL')
                        ->url()
                        ->placeholder('https://www.youtube.com/@channel/live  or a watch link')
                        ->helperText('Channel-style /live links work — the current live video is resolved automatically. Make sure "Allow embedding" is ON in YouTube Studio, or in-app/website playback is blocked.'),
                    Forms\Components\TextInput::make('youtube_channel_id')
                        ->label('YouTube Channel ID')
                        ->placeholder('UCxxxxxxxxxxxxxxxxxxxxxx')
                        ->rule('regex:/^(UC[A-Za-z0-9_-]{22})?$/')
                        // Anything that isn't a real UC… id is ignored by the
                        // resolver, so reject it here rather than let it look
                        // configured (a bare handle sat in this field for
                        // months, quietly resolving nothing).
                        ->validationMessages(['regex' => 'Must be a channel ID starting with UC (not a @handle or a URL).'])
                        ->helperText('Optional. Leave blank and the @handle in the URL above is used.'),
                    Forms\Components\TextInput::make('youtube_api_key')
                        ->label('YouTube Data API key')
                        ->password()
                        ->revealable()
                        ->autocomplete(false)
                        ->helperText('Required for /@handle/live links: YouTube blocks the server from reading the channel page, so the current live video is looked up through this key. Google Cloud → APIs & Services → enable "YouTube Data API v3" → create an API key. Free.'),
                    Forms\Components\FileUpload::make('live_darshan_placeholder_image')
                        ->label('Offline image (closed hours)')
                        ->image()
                        ->disk('r2')
                        ->directory('live-darshan')
                        ->maxSize(4096)
                        ->helperText('Shown on the live card whenever the stream is not running. Recommended 16:9, e.g. 1280×720. Empty = today\'s daily darshan photo.'),
                ])
                ->action(function (array $data) {
                    foreach ($data as $key => $value) {
                        if (is_array($value)) {
                            $value = (string) (reset($value) ?: '');
                        }
                        SystemSetting::updateOrCreate(
                            ['key' => $key],
                            ['value' => $value ?? '', 'group' => 'general', 'updated_at' => now()],
                        );
                    }

                    Notification::make()
                        ->title('Live darshan settings saved')
                        ->success()
                        ->send();
                }),
            Actions\CreateAction::make(),
        ];
    }
}
