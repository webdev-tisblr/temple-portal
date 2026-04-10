<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Seva;
use App\Services\SevaSlotService;
use Illuminate\Console\Command;

class MigrateSevaSlotConfig extends Command
{
    protected $signature = 'seva:migrate-slot-config';

    protected $description = 'Migrate all seva slot_config from v1 to v2 format';

    public function handle(SevaSlotService $slotService): int
    {
        $sevas = Seva::withTrashed()->whereNotNull('slot_config')->get();

        if ($sevas->isEmpty()) {
            $this->info('No sevas with slot_config found.');
            return self::SUCCESS;
        }

        $migrated = 0;
        $skipped = 0;

        foreach ($sevas as $seva) {
            $config = $seva->slot_config;

            if (($config['version'] ?? null) === 2) {
                $skipped++;
                continue;
            }

            $newConfig = $slotService->normalizeConfig($config);
            $seva->update(['slot_config' => $newConfig]);
            $migrated++;

            $this->line("  Migrated: {$seva->name_en} (ID: {$seva->id})");
        }

        $this->newLine();
        $this->info("Done. Migrated: {$migrated}, Skipped (already v2): {$skipped}");

        return self::SUCCESS;
    }
}
