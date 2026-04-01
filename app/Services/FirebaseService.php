<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;

class FirebaseService
{
    public function sendToDevice(string $token, string $title, string $body, array $data = []): bool
    {
        // kreait/laravel-firebase may not be configured yet — graceful fallback
        try {
            $messaging = app('firebase.messaging');
            $message = \Kreait\Firebase\Messaging\CloudMessage::withTarget('token', $token)
                ->withNotification(['title' => $title, 'body' => $body])
                ->withData($data);
            $messaging->send($message);
            Log::info("FCM sent to device", ['token' => substr($token, 0, 20) . '...']);
            return true;
        } catch (\Exception $e) {
            Log::warning("FCM send failed", ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function sendToMultiple(array $tokens, string $title, string $body, array $data = []): array
    {
        $results = ['success' => 0, 'failure' => 0, 'invalid_tokens' => []];
        if (empty($tokens)) return $results;

        try {
            $messaging = app('firebase.messaging');
            $message = \Kreait\Firebase\Messaging\CloudMessage::new()
                ->withNotification(['title' => $title, 'body' => $body])
                ->withData($data);

            // Send in batches of 500
            $chunks = array_chunk($tokens, 500);
            foreach ($chunks as $chunk) {
                try {
                    $report = $messaging->sendMulticast($message, $chunk);
                    $results['success'] += $report->successes()->count();
                    $results['failure'] += $report->failures()->count();
                    // Collect invalid tokens
                    foreach ($report->failures()->getItems() as $failure) {
                        if (str_contains($failure->error()->getMessage(), 'not-registered') || str_contains($failure->error()->getMessage(), 'invalid-registration')) {
                            $results['invalid_tokens'][] = $failure->target()->value();
                        }
                    }
                } catch (\Exception $e) {
                    $results['failure'] += count($chunk);
                    Log::warning("FCM batch failed", ['error' => $e->getMessage()]);
                }
            }
        } catch (\Exception $e) {
            Log::warning("FCM not configured", ['error' => $e->getMessage()]);
            $results['failure'] = count($tokens);
        }

        return $results;
    }
}
