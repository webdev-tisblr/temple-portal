<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ContactSubmission;
use App\Models\Devotee;
use App\Models\Donation;
use App\Models\HallBooking;
use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\Receipt80G;
use App\Models\SevaBooking;
use App\Models\SystemSetting;
use App\Services\Notifications\NotificationContext;
use Illuminate\Console\Command;

/**
 * Iterate every enabled WhatsApp notification template and resolve
 * every {{ token }} in its wa_components blueprint against a real
 * recent dispatch context. Print a table listing which tokens come
 * up empty so the admin knows exactly which mappings to fix.
 *
 * Meta's WhatsApp Cloud API rejects template sends when a required
 * text parameter is empty ((#131008) or count mismatch (#132000)) —
 * the user reports both errors in production. This command is the
 * triage tool: run it, see which placeholder_map entries don't
 * resolve, fix them in Filament admin, run again to verify.
 *
 * Context synthesis mirrors what each dispatch site actually passes:
 *   donation.confirmed     → PaymentCaptureService dispatches
 *                            { donation, devotee, trust_name }
 *   donation.receipt_80g   → Generate80GReceipt dispatches
 *                            { devotee, receipt, donation, donor_name,
 *                              amount, amount_formatted, receipt_pdf_url,
 *                              greeting_card_url, trust_name }
 *   seva.booking.confirmed → { booking, devotee, trust_name }
 *   hall.booking.confirmed → { booking (array), devotee, trust_name }
 *   store.order.confirmed  → { devotee, order (array), trust_name }
 *   auth.otp               → { phone, otp, expires_in_minutes, ... }
 *   contact.submitted      → { submission, trust_name }
 *   devotee.registered     → { devotee, trust_name }
 *   devotee.birthday       → { devotee, name, language, trust_name }
 *
 * Each context uses the most recent matching real entity. If no row
 * exists yet for a trigger (eg no captured donation yet), the
 * template is reported as "no test data" — admin runs again later.
 */
class AuditNotificationPlaceholders extends Command
{
    protected $signature = 'notifications:audit-placeholders
                            {--channel=whatsapp : Limit audit to one channel (whatsapp/email/sms)}
                            {--trigger= : Limit audit to one trigger key}';

    protected $description = 'Resolve every placeholder in enabled WhatsApp templates against real data and report empty results';

    public function handle(): int
    {
        $channel = (string) $this->option('channel');
        $trigger = $this->option('trigger');

        $query = NotificationTemplate::where('is_enabled', true);
        if ($channel) $query->where('channel', $channel);
        if ($trigger) $query->where('key', $trigger);

        $templates = $query->orderBy('key')->orderBy('channel')->get();

        if ($templates->isEmpty()) {
            $this->warn('No enabled templates match the filters.');
            return self::SUCCESS;
        }

        $this->info("Auditing {$templates->count()} enabled template(s) — '{$channel}' channel\n");

        $hasFailures = false;

        foreach ($templates as $template) {
            $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->line("Template #{$template->id}  •  {$template->key}  •  {$template->channel}");
            $this->line("Meta name: " . ($template->wa_template_name ?: '—'));

            $context = $this->buildContextFor($template->key);
            if ($context === null) {
                $this->warn("  Skipped: no synthesis rule for trigger '{$template->key}'");
                continue;
            }

            if (! empty($context['__no_data'])) {
                $this->warn("  Skipped: no real entity exists yet for trigger '{$template->key}'");
                continue;
            }

            $ctx = new NotificationContext($context);
            $rows = $this->resolveTemplateParameters($template, $ctx);

            if (empty($rows)) {
                $this->line("  No text parameters declared in wa_components — nothing to resolve.");
                continue;
            }

            $tableRows = [];
            foreach ($rows as $row) {
                $value = $row['resolved'];
                $displayValue = $value === '' ? '<fg=red>EMPTY</>' : '<fg=green>' . $this->truncate($value, 50) . '</>';
                $tableRows[] = [
                    $row['component'],
                    $row['token'],
                    $row['mapped_path'],
                    $displayValue,
                ];
                if ($value === '') {
                    $hasFailures = true;
                }
            }

            $this->table(
                ['Component', 'Token', 'Mapped path', 'Resolved value'],
                $tableRows,
            );
        }

        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        if ($hasFailures) {
            $this->error('At least one parameter resolved EMPTY. Fix the mapping in Filament admin → Notification Templates → edit the template → adjust the value_token mapping.');
            return self::FAILURE;
        }
        $this->info('All parameters resolved successfully against the most-recent real data.');
        return self::SUCCESS;
    }

    /**
     * Walk wa_components, resolve each text parameter the way
     * WhatsAppNotificationDriver does, and return rows describing
     * what happened for each. Mirrors the driver's logic exactly so
     * the audit reflects real behaviour, not a guess.
     *
     * @return array<int, array{component: string, token: string, mapped_path: string, resolved: string}>
     */
    private function resolveTemplateParameters(NotificationTemplate $template, NotificationContext $ctx): array
    {
        $components = $template->wa_components ?? [];
        $placeholderMap = $template->placeholder_map ?? [];
        $rows = [];

        foreach ($components as $component) {
            if (! is_array($component) || empty($component['type'])) continue;
            $componentLabel = $component['type'] . (isset($component['sub_type']) ? "/{$component['sub_type']}" : '');

            foreach (($component['parameters'] ?? []) as $idx => $p) {
                if (! is_array($p)) continue;
                $type = $p['type'] ?? 'text';
                if ($type !== 'text') continue; // only text params have the "empty body" issue

                $token = $p['value_token'] ?? null;
                $literal = $p['value'] ?? null;

                if ($token !== null) {
                    $mappedPath = $placeholderMap[$token] ?? $token;
                    $resolved = $ctx->getForDisplay($mappedPath, '');
                    $rows[] = [
                        'component' => $componentLabel . "[{$idx}]",
                        'token' => $token,
                        'mapped_path' => $mappedPath,
                        'resolved' => $resolved,
                    ];
                } else {
                    $resolved = $ctx->render((string) ($literal ?? ''), $placeholderMap);
                    $rows[] = [
                        'component' => $componentLabel . "[{$idx}]",
                        'token' => '(literal)',
                        'mapped_path' => $literal ?: '(empty)',
                        'resolved' => $resolved,
                    ];
                }
            }
        }
        return $rows;
    }

    /**
     * Build the same context array each trigger's dispatch site passes
     * to NotificationService->dispatch(). Returns null when the trigger
     * key is unknown; returns `['__no_data' => true]` when there's no
     * real entity yet (eg no captured donation on a fresh install).
     */
    private function buildContextFor(string $triggerKey): ?array
    {
        $trustName = SystemSetting::getValue('trust_name', 'Shree Pataliya Hanumanji Seva Trust');

        switch ($triggerKey) {
            case 'donation.confirmed':
                $donation = Donation::query()
                    ->whereHas('payment', fn ($q) => $q->where('status', 'captured'))
                    ->with('devotee', 'campaign', 'donationType')
                    ->latest('id')->first();
                if (! $donation) return ['__no_data' => true];
                return [
                    'donation' => $donation,
                    'devotee' => $donation->devotee,
                    'trust_name' => $trustName,
                ];

            case 'donation.receipt_80g':
                $receipt = Receipt80G::with('donation.devotee')->latest('id')->first();
                if (! $receipt) return ['__no_data' => true];
                $donation = $receipt->donation;
                // Mirror Generate80GReceipt::handle()'s dispatch context
                // EXACTLY — every key here must match what the real job
                // publishes, otherwise this audit becomes a lying oracle
                // (reports empty for fields production resolves correctly,
                // or vice-versa). When changing this list, also update
                // the real dispatch site.
                return [
                    'devotee' => $donation->devotee,
                    'receipt' => $receipt->toArray(),
                    'donation' => $donation,
                    'name' => $donation->devotee?->name,
                    'donor_name' => $donation->devotee?->name,
                    'amount' => (string) $donation->amount,
                    'amount_formatted' => number_format((float) $donation->amount, 2),
                    'receipt_pdf_url' => '(presigned URL — generated at dispatch)',
                    'trust_name' => $trustName,
                ];

            case 'donation.greeting_card':
                $donation = Donation::query()
                    ->whereNotNull('greeting_card_path')
                    ->whereHas('donationType', fn ($q) => $q
                        ->whereNotNull('greeting_card_template')
                        ->whereNotNull('greeting_card_config'))
                    ->with('devotee', 'donationType')
                    ->latest('id')->first();
                if (! $donation) return ['__no_data' => true];
                // Mirror GenerateGreetingCard::handle()'s dispatch context.
                // Keep in sync with the real job when either changes.
                return [
                    'devotee' => $donation->devotee,
                    'donation' => $donation,
                    'name' => $donation->devotee?->name,
                    'donor_name' => $donation->devotee?->name,
                    'amount' => (string) $donation->amount,
                    'amount_formatted' => number_format((float) $donation->amount, 2),
                    'greeting_card_url' => route('donation.greeting-card', $donation),
                    'trust_name' => $trustName,
                ];

            case 'seva.booking.confirmed':
                $booking = SevaBooking::query()
                    ->where('status', 'confirmed')
                    ->with('devotee', 'seva')
                    ->latest('id')->first();
                if (! $booking) return ['__no_data' => true];
                return [
                    'booking' => $booking,
                    'devotee' => $booking->devotee,
                    'trust_name' => $trustName,
                ];

            case 'hall.booking.confirmed':
                $booking = HallBooking::query()
                    ->where('status', 'confirmed')
                    ->with('devotee', 'hall')
                    ->latest('id')->first();
                if (! $booking) return ['__no_data' => true];
                $bookingNumber = 'HALL-' . $booking->id . '-' . $booking->created_at->format('Ymd');
                $bookingTypeLabel = match ($booking->booking_type) {
                    'full_day' => 'Full Day',
                    'half_day_morning' => 'Half Day (Morning)',
                    'half_day_evening' => 'Half Day (Evening)',
                    default => ucfirst((string) $booking->booking_type),
                };
                return [
                    'booking' => array_merge($booking->toArray(), [
                        'booking_number' => $bookingNumber,
                        'booking_type_label' => $bookingTypeLabel,
                        'booking_date' => $booking->booking_date?->format('d M Y'),
                        'total_amount_formatted' => number_format((float) $booking->total_amount, 2),
                        'contact_phone' => $booking->contact_phone,
                        'contact_name' => $booking->contact_name,
                        'hall' => $booking->hall?->toArray(),
                    ]),
                    'devotee' => $booking->devotee,
                    'trust_name' => $trustName,
                ];

            case 'store.order.confirmed':
                $order = Order::query()
                    ->where('status', 'confirmed')
                    ->with('devotee', 'items')
                    ->latest('id')->first();
                if (! $order) return ['__no_data' => true];
                return [
                    'devotee' => $order->devotee,
                    'order' => array_merge($order->toArray(), [
                        'total_amount_formatted' => number_format((float) $order->total_amount, 2),
                        'items_count' => $order->items?->count() ?? 0,
                    ]),
                    'trust_name' => $trustName,
                ];

            case 'auth.otp':
                // Synthetic — OTPs are short-lived so we never have a
                // "real recent" one in DB. Use placeholder values that
                // exercise the same resolution paths.
                $devotee = Devotee::latest('id')->first();
                return [
                    'phone' => $devotee?->phone ?? '9876543210',
                    'otp' => '123456',
                    'expires_in_minutes' => 10,
                    'devotee' => $devotee,
                    'email' => $devotee?->email,
                    'name' => $devotee?->name,
                ];

            case 'contact.submitted':
                $submission = ContactSubmission::latest('id')->first();
                if (! $submission) return ['__no_data' => true];
                return [
                    'submission' => $submission,
                    'trust_name' => $trustName,
                ];

            case 'devotee.registered':
                $devotee = Devotee::latest('id')->first();
                if (! $devotee) return ['__no_data' => true];
                return [
                    'devotee' => $devotee,
                    'trust_name' => $trustName,
                ];

            case 'devotee.birthday':
                $devotee = Devotee::latest('id')->first();
                if (! $devotee) return ['__no_data' => true];
                return [
                    'devotee' => $devotee,
                    'name' => $devotee->name,
                    'language' => $devotee->preferred_language ?? 'gu',
                    'trust_name' => $trustName,
                ];

            default:
                return null;
        }
    }

    private function truncate(string $value, int $maxLength): string
    {
        return mb_strlen($value) > $maxLength
            ? mb_substr($value, 0, $maxLength) . '…'
            : $value;
    }
}
