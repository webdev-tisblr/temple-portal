<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The ₹ sign now lives in the amount VALUE, not the template body.
 *
 * Amount placeholders render through inr_money(): "₹1,00,000" — Indian
 * digit grouping, and paise only when there are paise. A message parameter
 * often stands alone (a WhatsApp body binds {{n}} positionally with no
 * markup around it), so the value has to carry its own sign.
 *
 * Every email body written before this printed its own ₹ immediately
 * before the token, which would now render "₹₹1,00,000". This strips that
 * one leading sign from the three tokens that carry money — verified as
 * the only ones affected: 9 spots across 7 templates. gst_rate is a
 * percentage and is deliberately not touched.
 *
 * Two placeholder maps also named a RAW model column (donation.amount,
 * booking.total_amount), which bypasses the formatting entirely; they are
 * repointed at the formatted context key.
 */
return new class extends Migration
{
    /** Tokens whose value now includes the sign. NOT gst_rate — that is "18%". */
    private const MONEY_TOKENS = ['amount', 'campaign_raised', 'campaign_goal'];

    private const RAW_PATHS = [
        'donation.amount' => 'amount_formatted',
        'booking.total_amount' => 'booking.total_amount_formatted',
    ];

    public function up(): void
    {
        $this->stripLeadingRupee();
        $this->repointRawAmountPaths(self::RAW_PATHS);
    }

    public function down(): void
    {
        $this->restoreLeadingRupee();
        $this->repointRawAmountPaths(array_flip(self::RAW_PATHS));
    }

    /**
     * Remove ONE ₹ (and any space after it) directly before a money token.
     * Anchored to the token so a ₹ used anywhere else in the body — a
     * heading, a fixed price in prose — is left alone.
     */
    private function stripLeadingRupee(): void
    {
        $pattern = '/₹[ \t]*(\{\{\s*(?:'.implode('|', self::MONEY_TOKENS).')\s*\}\})/u';

        $this->rewriteBodies(fn (string $text): string => (string) preg_replace($pattern, '$1', $text));
    }

    private function restoreLeadingRupee(): void
    {
        $pattern = '/(?<!₹)(\{\{\s*(?:'.implode('|', self::MONEY_TOKENS).')\s*\}\})/u';

        $this->rewriteBodies(fn (string $text): string => (string) preg_replace($pattern, '₹$1', $text));
    }

    /** Apply a rewrite to every template's subject and body. */
    private function rewriteBodies(callable $rewrite): void
    {
        $templates = DB::table('temple_notification_templates')->get(['id', 'subject', 'body']);

        foreach ($templates as $template) {
            $subject = (string) ($template->subject ?? '');
            $body = (string) ($template->body ?? '');

            $newSubject = $rewrite($subject);
            $newBody = $rewrite($body);

            if ($newSubject === $subject && $newBody === $body) {
                continue;
            }

            DB::table('temple_notification_templates')
                ->where('id', $template->id)
                ->update(['subject' => $newSubject, 'body' => $newBody]);
        }
    }

    /**
     * @param  array<string, string>  $moves  old path => new path
     */
    private function repointRawAmountPaths(array $moves): void
    {
        $templates = DB::table('temple_notification_templates')->get(['id', 'placeholder_map']);

        foreach ($templates as $template) {
            $map = json_decode((string) ($template->placeholder_map ?? ''), true);

            if (! is_array($map)) {
                continue;
            }

            $changed = false;
            foreach ($map as $token => $path) {
                if (is_string($path) && isset($moves[$path])) {
                    $map[$token] = $moves[$path];
                    $changed = true;
                }
            }

            if (! $changed) {
                continue;
            }

            DB::table('temple_notification_templates')
                ->where('id', $template->id)
                ->update(['placeholder_map' => json_encode($map)]);
        }
    }
};
