<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Seva;
use Illuminate\Support\Facades\Cache;

class SevaObserver
{
    public function created(Seva $seva): void
    {
        $this->clearCache();
    }

    public function updated(Seva $seva): void
    {
        $this->clearCache();
    }

    public function deleted(Seva $seva): void
    {
        $this->clearCache();
    }

    private function clearCache(): void
    {
        Cache::forget('web_active_sevas'); // web /seva listing (SevaWebController)
        Cache::forget('homepage_seva_categories'); // web home category cards (HomeController)
        Cache::forget('homepage_sevas'); // legacy home carousel key — harmless to keep busting one cycle

        // /api/v1/seva-categories bakes seva_count + single-seva links into
        // its localized payload — any seva change can shift both.
        \App\Support\LocalizedCache::forget('seva.categories');

        // API list caches are keyed api_sevas.v{ver}.{category}.p{page} —
        // bumping the version invalidates every page/category at once.
        Cache::forever('sevas.cache.ver', (int) Cache::get('sevas.cache.ver', 1) + 1);

        // Legacy keys from before the web/API split — harmless to keep
        // busting through one deploy cycle.
        Cache::forget('active_sevas');
        foreach (['shringar', 'vastra', 'annadan', 'puja', 'special', 'other'] as $cat) {
            Cache::forget("active_sevas_{$cat}");
        }
    }
}
