<?php

namespace Tests\Unit;

use App\Services\Notifications\NotificationRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Guards on the registry's own shape (2026-08-14).
 *
 * The registry is documentation that is ALSO executable: EditNotificationTemplate
 * ::buildPlaceholderMap() parses each description's trailing "(dot.path)" to
 * auto-fill a template's placeholder_map. Two ways that bites, both of which
 * had actually happened:
 *
 *   • Text after the path — the regex is anchored to the end of the string, so
 *     the path is ignored and the token name is used instead. Silent: the
 *     admin sees a plausible mapping and an empty value at send time.
 *   • A path naming a context key no dispatch site publishes. Meta rejects a
 *     template send whose parameter resolves to "", so this surfaces as a
 *     failed message with a blank where the amount should be.
 *
 * These assert the first mechanically. The second needs real data —
 * `php artisan notifications:audit-placeholders` is the tool for it.
 */
class NotificationRegistryPathsTest extends TestCase
{
    public function test_every_description_ends_with_its_path(): void
    {
        $offenders = [];

        foreach (NotificationRegistry::all() as $key => $trigger) {
            foreach ($trigger['placeholders'] as $token => $description) {
                if (preg_match('/\(([^)]+)\)\s*$/', (string) $description)) {
                    continue;
                }

                // No trailing group is fine when the description names no path
                // at all — the token IS the top-level key (trust_name, otp).
                // It is only a bug when a path is named somewhere else in the
                // string, because then the author meant it to be used.
                if (preg_match('/\((?:[a-z_]+\.[a-zA-Z_.]+|[a-z_]+_(?:formatted|label|url|reference))\)/', (string) $description)) {
                    $offenders[] = "{$key}.{$token}";
                }
            }
        }

        $this->assertSame([], $offenders,
            'These descriptions name a dot-path that is not the LAST thing in the string, so '
            .'buildPlaceholderMap() will ignore it and map the token to its own name instead. '
            .'Move the explanatory text before the path.');
    }

    public function test_no_trigger_offers_two_tokens_for_one_fact(): void
    {
        // Per-language twins (seva_name_en, campaign_title_hi, …) and the
        // amount/amount_formatted pair were both withdrawn: the base token
        // already renders in the reader's language and already carries ₹.
        $offenders = [];

        foreach (NotificationRegistry::all() as $key => $trigger) {
            foreach (array_keys($trigger['placeholders']) as $token) {
                if (preg_match('/_(hi|en)$/', $token) || $token === 'amount_formatted') {
                    $offenders[] = "{$key}.{$token}";
                }
            }
        }

        $this->assertSame([], $offenders,
            'One token per fact: the base token localizes itself and carries its own ₹.');
    }

    public function test_every_trigger_documents_at_least_one_placeholder(): void
    {
        foreach (NotificationRegistry::all() as $key => $trigger) {
            $this->assertNotEmpty($trigger['placeholders'], "{$key} documents no placeholders");
            $this->assertNotEmpty($trigger['label'], "{$key} has no label");
        }
    }
}
