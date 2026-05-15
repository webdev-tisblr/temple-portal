<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DeviceToken;
use Illuminate\Console\Command;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FcmNotification;
use Throwable;

/**
 * One-shot FCM diagnostic. Picks the most-recent active device token and
 * tries a direct kreait send so the actual FCM error (project mismatch,
 * permission denied, invalid token, etc.) is surfaced on stdout.
 *
 * Usage on Hostinger:
 *   cd ~/domains/patadiyahanumanji.com/public_html && php artisan fcm:test
 *
 * Safe to keep in the codebase — does nothing unless invoked.
 */
class FcmTestCommand extends Command
{
    protected $signature = 'fcm:test {--token= : Send to a specific token instead of the latest active one}';

    protected $description = 'Diagnose FCM by sending a direct kreait test message and surfacing the raw error';

    public function handle(): int
    {
        $tokenValue = $this->option('token');
        $devoteeId = null;
        $platform = null;

        if ($tokenValue) {
            $this->line('Using --token override');
        } else {
            $row = DeviceToken::where('is_active', true)->latest()->first();
            if (! $row) {
                $this->error('No active device tokens in temple_device_tokens. Nothing to test.');
                return self::FAILURE;
            }
            $tokenValue = $row->token;
            $devoteeId = $row->devotee_id;
            $platform = $row->platform;
        }

        $credsPath = config('firebase.projects.app.credentials');
        $this->line('--- Config ---');
        $this->line('Credentials path: ' . $credsPath);
        $this->line('File exists:      ' . (is_file($credsPath) ? 'yes' : 'NO'));
        $this->line('File readable:    ' . (is_readable($credsPath) ? 'yes' : 'NO'));

        if (is_file($credsPath)) {
            $raw = @file_get_contents($credsPath);
            $decoded = $raw ? json_decode($raw, true) : null;
            $this->line('Project ID in JSON: ' . ($decoded['project_id'] ?? '<missing>'));
            $this->line('Client email:       ' . ($decoded['client_email'] ?? '<missing>'));
        }

        $this->newLine();
        $this->line('--- Target ---');
        $this->line('Token:    ' . substr($tokenValue, 0, 40) . '...');
        $this->line('Devotee:  ' . ($devoteeId ?? '(custom)'));
        $this->line('Platform: ' . ($platform ?? '(unknown)'));

        $this->newLine();
        $this->line('--- Sending ---');

        try {
            $messaging = app('firebase.messaging');
            $msg = CloudMessage::withTarget('token', $tokenValue)
                ->withNotification(FcmNotification::create('Diagnostic', 'Direct FCM send test'));
            $result = $messaging->send($msg);
            $this->info('SUCCESS — FCM accepted the message.');
            $this->line('Response: ' . json_encode($result));
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('FAILED');
            $this->line('Class:   ' . get_class($e));
            $this->line('Message: ' . $e->getMessage());

            if (method_exists($e, 'errors')) {
                $details = $e->errors();
                if (! empty($details)) {
                    $this->line('Errors:  ' . json_encode($details));
                }
            }

            return self::FAILURE;
        }
    }
}
