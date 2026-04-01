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
        Cache::forget('active_sevas');
        foreach (['shringar', 'vastra', 'annadan', 'puja', 'special', 'other'] as $cat) {
            Cache::forget("active_sevas_{$cat}");
        }
    }
}
