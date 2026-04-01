<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendPushNotification;
use App\Jobs\SendWhatsAppMessage;
use App\Models\DeviceToken;
use App\Models\Devotee;
use App\Models\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendBirthdayBlessings extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'temple:send-birthday-blessings';

    /**
     * The console command description.
     */
    protected $description = 'Send birthday blessings to devotees whose birthday falls on today';

    public function handle(): int
    {
        $today = now();
        $month = (int) $today->month;
        $day = (int) $today->day;

        $devotees = Devotee::query()
            ->whereNotNull('date_of_birth')
            ->where('is_active', true)
            ->whereMonth('date_of_birth', $month)
            ->whereDay('date_of_birth', $day)
            ->get();

        $count = $devotees->count();

        if ($count === 0) {
            $this->info('No birthdays today.');
            Log::info('temple:send-birthday-blessings: no birthdays today.');
            return self::SUCCESS;
        }

        $this->info("Found {$count} devotee(s) with birthdays today. Dispatching blessings...");

        foreach ($devotees as $devotee) {
            // Dispatch WhatsApp birthday blessing
            SendWhatsAppMessage::dispatch(
                $devotee->phone,
                'template',
                [
                    'template_name' => 'birthday_blessing',
                    'language_code' => $this->resolveLanguageCode($devotee->language?->value ?? 'gu'),
                    'components' => [
                        [
                            'type' => 'body',
                            'parameters' => [
                                ['type' => 'text', 'text' => $devotee->name],
                            ],
                        ],
                    ],
                ],
            );

            // Dispatch push notification if device token exists
            $hasToken = DeviceToken::where('devotee_id', $devotee->id)
                ->where('is_active', true)
                ->exists();

            if ($hasToken) {
                $pushNotification = Notification::create([
                    'title_gu' => 'જન્મદિવસ મુબારક! 🙏',
                    'title_hi' => 'जन्मदिन मुबारक! 🙏',
                    'title_en' => 'Happy Birthday! 🙏',
                    'body_gu' => "પ્રિય {$devotee->name}, શ્રી પાતળિયા હનુમાનજી આપને જન્મદિવસ પર આશીર્વાદ આપે. 🙏",
                    'body_hi' => "प्रिय {$devotee->name}, श्री पातळिया हनुमानजी आपको जन्मदिन पर आशीर्वाद देते हैं। 🙏",
                    'body_en' => "Dear {$devotee->name}, Shree Pataliya Hanumanji blesses you on your birthday. 🙏",
                    'segment' => 'custom',
                    'custom_filter' => ['devotee_ids' => [$devotee->id]],
                    'status' => 'pending',
                    'scheduled_at' => now(),
                ]);

                SendPushNotification::dispatch($pushNotification);
            }
        }

        $this->info("Birthday blessings dispatched for {$count} devotee(s).");
        Log::info("temple:send-birthday-blessings: dispatched for {$count} devotee(s).", [
            'month' => $month,
            'day' => $day,
        ]);

        return self::SUCCESS;
    }

    private function resolveLanguageCode(string $language): string
    {
        return match ($language) {
            'hi' => 'hi',
            'en' => 'en',
            default => 'gu', // Gujarati
        };
    }
}
