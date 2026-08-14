<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AdminUser;
use App\Models\HallReminderRule;
use App\Models\HallReminderSchedule;
use App\Models\NotificationTemplate;
use App\Models\SystemSetting;
use App\Services\HallReminderScheduler;
use App\Services\Notifications\NotificationContext;
use App\Services\Notifications\NotificationService;
use App\Support\DurationLabel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sends hall booking reminders whose moment has arrived.
 *
 * Twin of DispatchSevaReminders. Runs every five minutes; each row is sent
 * once and marked, so a slow run cannot double-send.
 */
class DispatchHallReminders extends Command
{
    protected $signature = 'hall:dispatch-reminders
                            {--limit=500 : Rows per run}
                            {--max-late-minutes=720 : Skip anything later than this}';

    protected $description = 'Send due hall booking reminders';

    public function handle(NotificationService $notifier, HallReminderScheduler $scheduler): int
    {
        $now = now();
        $staleCutoff = $now->copy()->subMinutes((int) $this->option('max-late-minutes'));

        $due = HallReminderSchedule::query()
            ->where('status', HallReminderSchedule::STATUS_PENDING)
            ->where('fire_at', '<=', $now)
            ->with(['booking.devotee', 'booking.hall', 'rule.template'])
            ->orderBy('fire_at')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($due->isEmpty()) {
            $this->info('No hall reminders due.');

            return self::SUCCESS;
        }

        $sent = 0;
        $skipped = 0;

        foreach ($due as $row) {
            $booking = $row->booking;

            // The booking went away or is no longer happening.
            if (! $booking || $booking->status !== 'confirmed') {
                $row->update(['status' => HallReminderSchedule::STATUS_SKIPPED]);
                $skipped++;

                continue;
            }

            // Too late to be useful, or the booking has already started —
            // a reminder after the fact is worse than none.
            if ($row->fire_at->lessThan($staleCutoff)
                || $scheduler->bookingMoment($booking)->lessThanOrEqualTo($now)) {
                $row->update(['status' => HallReminderSchedule::STATUS_SKIPPED]);
                $skipped++;

                continue;
            }

            try {
                $this->dispatchRule($notifier, $row->rule, $booking);
                $row->update([
                    'status' => HallReminderSchedule::STATUS_SENT,
                    'sent_at' => now(),
                ]);
                $sent++;
            } catch (\Throwable $e) {
                $row->update(['status' => HallReminderSchedule::STATUS_FAILED]);
                Log::error('HallReminder dispatch failed', [
                    'schedule_id' => $row->id,
                    'error' => $e->getMessage(),
                ]);
                $skipped++;
            }
        }

        $this->info("Hall reminders sent: {$sent}   skipped: {$skipped}");

        return self::SUCCESS;
    }

    private function dispatchRule(
        NotificationService $notifier,
        ?HallReminderRule $rule,
        $booking,
    ): void {
        if (! $rule) {
            return;
        }

        // Hall::name is a localized accessor, but it reads
        // app()->getLocale() — always the default `gu` inside a scheduled
        // command — so publish the raw per-language columns alongside it
        // and let NotificationContext pick per recipient.
        $hallAttributes = $booking->hall?->getAttributes() ?? [];

        $context = array_merge([
            'devotee' => $booking->devotee,
            'booking' => array_merge($booking->toArray(), [
                'booking_number' => 'HALL-'.$booking->id.'-'.$booking->created_at?->format('Ymd'),
                'hall_name' => $booking->hall?->name,
                'hall_name_gu' => $hallAttributes['name_gu'] ?? null,
                'hall_name_hi' => $hallAttributes['name_hi'] ?? null,
                'hall_name_en' => $hallAttributes['name_en'] ?? null,
                'booking_date' => $booking->booking_date?->format('d M Y'),
                'booking_date_range' => $booking->date_range_label,
                'days_count' => (int) ($booking->days_count ?: 1),
                'total_amount_formatted' => number_format((float) $booking->total_amount, 2),
                'contact_name' => $booking->contact_name,
                'contact_phone' => $booking->contact_phone,
            ]),
            // Parity with the seva reminder, which has always published a
            // bare hour count next to the phrase.
            'hours_remaining' => max(0, (int) round(((int) $rule->offset_minutes) / 60)),
            'trust_name' => SystemSetting::getValue('trust_name', 'Shree Patadiya Hanumanji Seva Trust'),
        ], DurationLabel::contextValues((int) $rule->offset_minutes));

        // Recipient expansion → [strategy, value, extraContext].
        $recipients = [];

        switch ($rule->recipient_type) {
            case HallReminderRule::RECIPIENT_DEVOTEE:
                // The booking's OWN contact number wins over the devotee's
                // registered one: it is the number given for this event, and
                // is often the person actually running it rather than whoever
                // paid. Falls back to the account when the booking has none —
                // the column is NOT NULL, so "none" means an empty string.
                //
                // Only meaningful for WhatsApp — email and push have no
                // contact-field equivalent, so they resolve to the devotee.
                $contact = trim((string) ($booking->contact_phone ?? ''));

                if ($contact !== '' && $rule->channel === NotificationTemplate::CHANNEL_WHATSAPP) {
                    $recipients[] = [NotificationTemplate::RECIPIENT_FIXED_PHONE, $contact, []];
                } else {
                    $recipients[] = [NotificationTemplate::RECIPIENT_DEVOTEE, null, []];
                }
                break;

            case HallReminderRule::RECIPIENT_ADMIN_ROLE:
                $role = trim((string) $rule->recipient_value);
                if ($role === '') {
                    Log::warning('Hall reminder rule: admin_role with no role name', ['rule_id' => $rule->id]);
                    break;
                }
                foreach (AdminUser::role($role)->where('is_active', true)->get() as $admin) {
                    $recipients[] = [NotificationTemplate::RECIPIENT_ADMIN_ROLE, $role, ['admin' => $admin]];
                }
                break;

            case HallReminderRule::RECIPIENT_CUSTOM_PHONE:
                foreach (preg_split('/[,\s]+/', (string) $rule->recipient_value) ?: [] as $phone) {
                    if (trim($phone) !== '') {
                        $recipients[] = [NotificationTemplate::RECIPIENT_FIXED_PHONE, trim($phone), []];
                    }
                }
                break;
        }

        if ($recipients === []) {
            return;
        }

        // The hirer reads their own language; staff and custom numbers get
        // Gujarati.
        $locale = 'gu';
        if ($rule->recipient_type === HallReminderRule::RECIPIENT_DEVOTEE) {
            $lang = $booking->devotee?->language;
            $locale = $lang instanceof \BackedEnum
                ? $lang->value
                : (is_string($lang) && $lang !== '' ? $lang : 'gu');
        }

        foreach ($recipients as [$strategy, $value, $extra]) {
            $template = $this->templateForRule($rule, $strategy, $value, $locale);
            if ($template === null) {
                continue;
            }

            $notifier->sendTemplate(
                $template,
                new NotificationContext(array_merge($context, $extra, ['locale' => $locale])),
            );
        }
    }

    /**
     * The in-memory template for one delivery.
     *
     * WhatsApp rules CLONE the stored Meta-approved row — keeping its id so
     * the audit log still links back — and override nothing but the
     * recipient, because Meta owns approved copy. Push/email build a
     * throwaway template from the rule's inline wording.
     */
    private function templateForRule(HallReminderRule $rule, string $strategy, ?string $value, string $locale): ?NotificationTemplate
    {
        if ($rule->channel === NotificationTemplate::CHANNEL_WHATSAPP) {
            $stored = $rule->template;
            if (! $stored) {
                Log::warning('Hall reminder rule: whatsapp rule with no template', ['rule_id' => $rule->id]);

                return null;
            }

            $t = $stored->replicate();
            $t->id = $stored->id;
            $t->exists = true;
            $t->recipient_strategy = $strategy;
            $t->recipient_value = $value;
            $t->recipients = null;

            return $t;
        }

        $title = $rule->titleFor($locale);
        $body = $rule->bodyFor($locale);

        if (! $title && ! $body) {
            Log::warning('Hall reminder rule: no inline copy for channel', [
                'rule_id' => $rule->id,
                'channel' => $rule->channel,
            ]);

            return null;
        }

        $t = new NotificationTemplate;
        $t->forceFill([
            'key' => 'hall.booking.reminder',
            'label' => "Hall reminder rule #{$rule->id}",
            'channel' => $rule->channel,
            'is_enabled' => true,
            'subject' => $title,
            'body' => $body,
            // MUST be locale-keyed arrays, not plain strings: the push driver
            // looks up [$locale] ?? ['gu'] ?? ['en'], and a bare string
            // silently dropped every inline push on the seva side until it
            // was found and fixed.
            'push_title' => [$locale => $title],
            'push_body' => [$locale => $body],
            'recipient_strategy' => $strategy,
            'recipient_value' => $value,
            'placeholder_map' => [
                'contact_name' => 'booking.contact_name',
                'hall_name' => 'booking.hall_name',
                'booking_date' => 'booking.booking_date',
                'booking_date_range' => 'booking.booking_date_range',
                'days_count' => 'booking.days_count',
                'amount' => 'booking.total_amount_formatted',
                'booking_number' => 'booking.booking_number',
                'contact_phone' => 'booking.contact_phone',
                'devotee_name' => 'devotee.name',
                // The account holder's own details — contact_* above is the
                // number written on the booking, which is often a different
                // person (the one running the event, not the one who paid).
                'devotee_phone' => 'devotee.phone',
                'devotee_email' => 'devotee.email',
                'hours_remaining' => 'hours_remaining',
                'time_remaining_label' => 'time_remaining_label',
                'trust_name' => 'trust_name',
                'admin_name' => 'admin.name',
            ],
        ]);

        return $t;
    }
}
