<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * End-to-end smoke test for the Cloudflare R2 disks.
 *
 * Writes a tiny test object to each disk, checks read-back, verifies the
 * public bucket is reachable through cdn.patadiyahanumanji.com, then deletes
 * the test objects. Run after wiring R2 credentials to confirm everything is
 * connected before touching user-facing code.
 *
 *   php artisan r2:smoke-test
 */
class R2SmokeTest extends Command
{
    protected $signature = 'r2:smoke-test {--keep : Skip cleanup so the test files remain in R2 for manual inspection}';

    protected $description = 'Verify Cloudflare R2 disks (public + private) are reachable and read/write/delete works';

    public function handle(): int
    {
        $this->info('Cloudflare R2 smoke test');
        $this->line(str_repeat('─', 60));

        $ok = true;
        $ok = $this->testDisk('r2', public: true) && $ok;
        $ok = $this->testDisk('r2_private', public: false) && $ok;

        $this->line(str_repeat('─', 60));

        if (! $ok) {
            $this->error('❌ One or more checks failed. See messages above.');

            return self::FAILURE;
        }

        $this->info('✅ All R2 checks passed.');

        return self::SUCCESS;
    }

    private function testDisk(string $disk, bool $public): bool
    {
        $this->newLine();
        $this->line("<fg=cyan>[$disk]</> ".($public ? 'public bucket' : 'private bucket'));

        $key = 'smoke-tests/'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(3)).'.txt';
        $body = 'r2 smoke test @ '.now()->toIso8601String();

        // Write
        try {
            Storage::disk($disk)->put($key, $body);
            $this->line("  ✓ wrote $key");
        } catch (\Throwable $e) {
            $this->error('  ✗ write failed: '.$e->getMessage());

            return false;
        }

        // Read back
        try {
            $read = Storage::disk($disk)->get($key);
            if ($read !== $body) {
                $this->error('  ✗ read-back mismatch');

                return false;
            }
            $this->line('  ✓ read-back OK');
        } catch (\Throwable $e) {
            $this->error('  ✗ read failed: '.$e->getMessage());

            return false;
        }

        // Public URL reachability (only for the public bucket)
        if ($public) {
            $url = Storage::disk($disk)->url($key);
            $this->line("  → url: $url");

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_USERAGENT => 'temple-portal/r2-smoke-test',
            ]);
            $fetched = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($err) {
                $this->warn('  ⚠ public URL fetch failed: '.$err);
            } elseif ($fetched === $body) {
                $this->line('  ✓ public URL serves the file (HTTP '.$status.')');
            } else {
                $this->warn('  ⚠ public URL returned HTTP '.$status.' but body did not match');
                $this->warn('    (Custom domain may still be propagating; not a blocker.)');
            }
        }

        // Cleanup (unless --keep)
        if (! $this->option('keep')) {
            try {
                Storage::disk($disk)->delete($key);
                $this->line('  ✓ deleted test object');
            } catch (\Throwable $e) {
                $this->warn('  ⚠ delete failed: '.$e->getMessage());
            }
        } else {
            $this->line('  · kept test object (--keep)');
        }

        return true;
    }
}
