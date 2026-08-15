<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AdminUser;
use App\Models\NotificationTemplate;
use App\Models\SevaBooking;
use App\Models\SevaReminderRule;
use App\Models\SevaReminderSchedule;
use App\Models\SystemSetting;
use App\Services\Notifications\NotificationContext;
use App\Services\Notifications\NotificationService;
use App\Services\SevaReminderScheduler;
use App\Support\DurationLabel;
use App\Support\RoleRecipients;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Fires reminder notifications for confirmed seva bookings.
 *
 * Reminders are PRE-COMPUTED: when a booking is confirmed,
 * SevaReminderScheduler writes one SevaReminderSchedule row per applicable
 * REMINDER RULE (or per legacy offset), stamped with the exact fire moment.
 * This command drains the due rows.
 *
 * Two dispatch paths per row:
 *   • rule_id set  → the rule carries recipient + channel + message. We
 *     build an in-memory NotificationTemplate per recipient and hand it to
 *     NotificationService::sendTemplate() — the audit log, drivers and
 *     recipient resolution all behave exactly as for stored templates.
 *     WhatsApp rules reference a real (Meta-approved) template row and
 *     only override its recipient.
 *   • rule_id null → legacy path: one dispatch of `seva.booking.reminder`,
 *     fanning out to every enabled template for that key (pre-rules
 *     behaviour, kept for sevas still on reminder_offsets).
 */
class DispatchSevaReminders extends Command
{
    protected $signature = 'seva:dispatch-reminders
        {--max-late-minutes=720 : Skip (don\'t send) reminders whose fire_at is older than this — too stale to be useful. Default 12h.}
        {--limit=500 : Maximum reminders to dispatch in a single run.}';

    protected $description = 'Dispatch due rows in the pre-computed seva reminder schedule (rule-based + legacy)';

    public function handle(NotificationService $notifier, SevaReminderScheduler $scheduler): int
    {
        $now = Carbon::now();
        $maxLate = max(1, (int) $this->option('max-late-minutes'));
        $limit = max(1, (int) $this->option('limit'));
        $staleCutoff = $now->copy()->subMinutes($maxLate);

        $trustName = SystemSetting::getValue('trust_name', 'Shree Patadiya Hanumanji Seva Trust');

        $due = SevaReminderSchedule::query()
            ->where('status', SevaReminderSchedule::STATUS_PENDING)
            ->where('fire_at', '<=', $now)
            ->with(['booking.devotee', 'booking.seva', 'rule.template'])
            ->orderBy('fire_at')
            ->limit($limit)
            ->get();

        $stats = ['due' => $due->count(), 'sent' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($due as $row) {
            try {
                $booking = $row->booking;

                // Booking gone or no longer going ahead → don't remind.
                $statusValue = $booking?->status instanceof \BackedEnum
                    ? $booking->status->value
                    : (string) ($booking?->status ?? '');
                if ($booking === null || $statusValue !== 'confirmed') {
                    $row->update(['status' => SevaReminderSchedule::STATUS_SKIPPED]);
                    $stats['skipped']++;

                    continue;
                }

                // Too stale to be useful, or the seva moment has already
                // passed — skip rather than send a pointless late reminder.
                $moment = $scheduler->bookingMoment($booking);
                if ($row->fire_at->lessThan($staleCutoff) || $moment->lessThanOrEqualTo($now)) {
                    $row->update(['status' => SevaReminderSchedule::STATUS_SKIPPED]);
                    $stats['skipped']++;

                    continue;
                }

                $offsetMinutes = SevaReminderScheduler::parseOffset($row->offset) ?? 0;

                $context = array_merge([
                    'booking' => $booking,
                    'devotee' => $booking->devotee,
                    'hours_remaining' => max(0, (int) round($offsetMinutes / 60)),
                    'trust_name' => $trustName,
                ], DurationLabel::contextValues($offsetMinutes));

                if ($row->rule !== null) {
                    $delivered = $this->dispatchRule($notifier, $row->rule, $booking, $context);
                    if ($delivered === 0) {
                        // Nothing usable to deliver to (no recipients / no
                        // template). Mark skipped for visibility, not failed.
                        $row->update(['status' => SevaReminderSchedule::STATUS_SKIPPED]);
                        $stats['skipped']++;

                        continue;
                    }
                } else {
                    // Legacy: fan out to every enabled template for the key.
                    $notifier->dispatch(
                        'seva.booking.reminder',
                        $context,
                        idempotencyKey: "seva-reminder:{$booking->getKey()}:{$row->offset}",
                    );
                }

                $row->update([
                    'status' => SevaReminderSchedule::STATUS_SENT,
                    'sent_at' => $now,
                ]);
                $stats['sent']++;
            } catch (\Throwable $e) {
                $row->update(['status' => SevaReminderSchedule::STATUS_FAILED]);
                $stats['failed']++;
                Log::error('seva:dispatch-reminders row failed', [
                    'schedule_id' => $row->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info(sprintf(
            'Seva reminders — due=%d sent=%d skipped=%d failed=%d',
            $stats['due'], $stats['sent'], $stats['skipped'], $stats['failed'],
        ));
        Log::info('seva:dispatch-reminders', $stats);

        return self::SUCCESS;
    }

    /**
     * Deliver one reminder rule: expand its recipient into concrete
     * deliveries and send each via an in-memory template. Returns the
     * number of deliveries attempted (0 = nothing usable).
     */
    private function dispatchRule(
        NotificationService $notifier,
        SevaReminderRule $rule,
        SevaBooking $booking,
        array $context,
    ): int {
        // Recipient expansion → list of [strategy, value, extraContext].
        $recipients = [];
        switch ($rule->recipient_type) {
            case SevaReminderRule::RECIPIENT_DEVOTEE:
                $recipients[] = [NotificationTemplate::RECIPIENT_DEVOTEE, null, []];
                break;

            case SevaReminderRule::RECIPIENT_ADMIN_ROLE:
                $role = trim((string) $rule->recipient_value);
                if ($role === '') {
                    Log::warning('Reminder rule: admin_role with no role name', ['rule_id' => $rule->id]);
                    break;
                }
                foreach (RoleRecipients::forRole($role, $rule->recipient_user_ids) as $admin) {
                    $recipients[] = [NotificationTemplate::RECIPIENT_ADMIN_ROLE, $role, ['admin' => $admin]];
                }
                break;

            case SevaReminderRule::RECIPIENT_ASSIGNEE:
                $assigneeId = $booking->seva?->assignee_id;
                $admin = $assigneeId ? AdminUser::find($assigneeId) : null;
                if ($admin) {
                    $recipients[] = [NotificationTemplate::RECIPIENT_ADMIN_USER, (string) $assigneeId, ['admin' => $admin]];
                } else {
                    Log::warning('Reminder rule: seva has no (active) assignee', ['rule_id' => $rule->id, 'seva_id' => $booking->seva?->id]);
                }
                break;

            case SevaReminderRule::RECIPIENT_CUSTOM_PHONE:
                foreach (preg_split('/[,\s]+/', (string) $rule->recipient_value) ?: [] as $phone) {
                    if (trim($phone) !== '') {
                        $recipients[] = [NotificationTemplate::RECIPIENT_FIXED_PHONE, trim($phone), []];
                    }
                }
                break;
        }

        if ($recipients === []) {
            return 0;
        }

        // Message language: the devotee's own language for devotee rules,
        // Gujarati for staff/custom recipients.
        $locale = 'gu';
        if ($rule->recipient_type === SevaReminderRule::RECIPIENT_DEVOTEE) {
            $lang = $booking->devotee?->language;
            $locale = $lang instanceof \BackedEnum ? $lang->value : (is_string($lang) && $lang !== '' ? $lang : 'gu');
        }

        $attempted = 0;
        foreach ($recipients as [$strategy, $value, $extra]) {
            $template = $this->templateForRule($rule, $strategy, $value, $locale);
            if ($template === null) {
                continue;
            }

            // 'locale' drives per-language template variant selection in
            // the WhatsApp driver (devotee's language for devotee rules,
            // Gujarati for staff/custom recipients).
            $ctx = new NotificationContext(array_merge($context, $extra, ['locale' => $locale]));
            $notifier->sendTemplate($template, $ctx);
            $attempted++;
        }

        return $attempted;
    }

    /**
     * The in-memory NotificationTemplate for a rule delivery. WhatsApp
     * rules clone their referenced (Meta-approved) template row and only
     * override the recipient; push/email rules carry their inline message.
     */
    private function templateForRule(SevaReminderRule $rule, string $strategy, ?string $value, string $locale): ?NotificationTemplate
    {
        if ($rule->channel === NotificationTemplate::CHANNEL_WHATSAPP) {
            $base = $rule->template;
            if (! $base) {
                Log::warning('Reminder rule: whatsapp rule has no template reference', ['rule_id' => $rule->id]);

                return null;
            }
            $t = (new NotificationTemplate)->setRawAttributes($base->getAttributes(), sync: true);
            $t->exists = true; // clone of a stored row — keep its id for the audit log
            $t->recipient_strategy = $strategy;
            $t->recipient_value = $value;
            $t->recipients = null; // rule recipient only — ignore the row's own list

            return $t;
        }

        $title = $rule->titleFor($locale);
        $body = $rule->bodyFor($locale);
        if (($title === null || $title === '') && ($body === null || $body === '')) {
            Log::warning('Reminder rule: no inline message configured', ['rule_id' => $rule->id, 'channel' => $rule->channel]);

            return null;
        }

        $t = new NotificationTemplate;
        $t->forceFill([
            'key' => 'seva.booking.reminder',
            'label' => "Reminder rule #{$rule->id}",
            'channel' => $rule->channel,
            'is_enabled' => true,
            'subject' => $title,
            'body' => $body,
            // push_title/push_body are array-cast (locale => text) on
            // NotificationTemplate. PushNotificationDriver looks up
            // $titles[$locale] ?? $titles['gu'] ?? $titles['en'] — a plain
            // string here made that lookup yield '' and silently dropped
            // every inline reminder push. Key by the rule's resolved
            // locale, which is the same 'locale' the dispatch context
            // carries, so the driver's first lookup always hits.
            'push_title' => [$locale => $title],
            'push_body' => [$locale => $body],
            'recipient_strategy' => $strategy,
            'recipient_value' => $value,
            'placeholder_map' => [
                'devotee_name' => 'devotee.name',
                // The devotee's own contact details, so a reminder aimed
                // at the pujari/staff can actually reach the person who
                // booked. assignee_phone below is the STAFF number and was
                // the only contact this trigger published.
                'devotee_phone' => 'devotee.phone',
                'devotee_email' => 'devotee.email',
                'admin_name' => 'admin.name',
                // `name_gu` is the fallback, not a hardcoding —
                // NotificationContext::getLocalized() prefers name_hi /
                // name_en for a Hindi/English recipient and only lands
                // here when that translation is blank.
                'seva_name' => 'booking.seva.name_gu',
                'booking_date' => 'booking.booking_date',
                // ...slot_time_label, so a full-day seva's reminder reads
                // "Whole day" instead of the raw 'full_day' sentinel.
                'slot_time' => 'booking.slot_time_label',
                'hours_remaining' => 'hours_remaining',
                'time_remaining_label' => 'time_remaining_label',
                // Both tokens resolve to the readable reference — `id` is a
                // 36-char UUID and was being printed into messages.
                'booking_reference' => 'booking.booking_reference',
                'booking_id' => 'booking.booking_reference',
                'assignee_name' => 'booking.seva.assignee.name',
                'assignee_phone' => 'booking.seva.assignee.phone',
                'trust_name' => 'trust_name',
            ],
        ]);

        return $t;
    }
}
