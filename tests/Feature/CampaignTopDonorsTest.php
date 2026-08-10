<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DonationCampaign;
use App\Support\CampaignDonors;
use Database\Factories\DevoteeFactory;
use Database\Factories\DonationFactory;
use Database\Factories\PaymentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Top donors" ranks DONORS BY THEIR TOTAL, not individual donations.
 *
 * The reported bug, exactly: a devotee who gave ₹1,000 five times did not
 * appear on the list at all, while a single ₹3,000 gift sat above their
 * ₹5,000. test_a_donor_who_gave_five_times_outranks_a_single_larger_gift()
 * is that case.
 *
 * The rest of this file pins the decisions that come with summing:
 *  • uncaptured money never joins a total;
 *  • Gupt Daan is out of Top entirely (in Recent it is still masked) — a
 *    masked TOTAL would be differenceable against Recent;
 *  • grouping is on the devotee UUID, so two donors sharing a name stay two
 *    rows, and one donor's offerings merge however the name was typed;
 *  • Recent is untouched: still one row per offering, newest first.
 *
 * MySQL only (ONLY_FULL_GROUP_BY is on — the aggregate SELECT has to be
 * exercised against the real engine, not a permissive one).
 */
class CampaignTopDonorsTest extends TestCase
{
    use RefreshDatabase;

    private function campaign(): DonationCampaign
    {
        return DonationCampaign::create([
            'title_gu' => 'ટોચના દાનકર્તા પરીક્ષણ',
            'title_en' => 'Top Donors Test',
            'slug' => 'top-donors-'.uniqid(),
            'goal_amount' => 500000,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'is_active' => true,
        ]);
    }

    /**
     * One captured offering. `$minutesAgo` keeps created_at deterministic so
     * the tie-breaker (most recent first) is testable.
     */
    private function offering(
        DonationCampaign $campaign,
        string $devoteeId,
        float $amount,
        int $minutesAgo = 0,
        bool $captured = true,
        bool $anonymous = false,
    ): void {
        $payment = $captured
            ? PaymentFactory::new()->captured()->create()
            : PaymentFactory::new()->create();

        DonationFactory::new()->create([
            'devotee_id' => $devoteeId,
            'payment_id' => $payment->id,
            'donation_type' => 'campaign',
            'campaign_id' => $campaign->id,
            'amount' => $amount,
            'anonymous' => $anonymous,
            'created_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    /** ★ The exact case the user reported. */
    public function test_a_donor_who_gave_five_times_outranks_a_single_larger_gift(): void
    {
        $campaign = $this->campaign();

        $a = DevoteeFactory::new()->create(['name' => 'Donor A', 'city' => 'Bhuj']);
        $b = DevoteeFactory::new()->create(['name' => 'Donor B', 'city' => 'Rajkot']);

        // A: five smaller offerings — ₹5,000 in total.
        foreach ([1000, 1000, 1000, 1000, 1000] as $i => $amount) {
            $this->offering($campaign, $a->id, (float) $amount, minutesAgo: 60 - $i);
        }

        // B: one larger offering, but less overall.
        $this->offering($campaign, $b->id, 3000, minutesAgo: 5);

        $rows = CampaignDonors::payload(
            CampaignDonors::top($campaign->id)->limit(CampaignDonors::PER_PAGE)->get()
        );

        $this->assertCount(2, $rows, 'one row per donor, not per donation');

        $this->assertSame('Donor A', $rows[0]['name'],
            'the donor with the larger TOTAL must come first');
        $this->assertSame(5000.0, $rows[0]['amount']);
        $this->assertSame(5, $rows[0]['donation_count']);
        $this->assertSame('Bhuj', $rows[0]['city']);

        $this->assertSame('Donor B', $rows[1]['name']);
        $this->assertSame(3000.0, $rows[1]['amount']);
        $this->assertSame(1, $rows[1]['donation_count']);
    }

    public function test_an_uncaptured_offering_never_joins_a_total(): void
    {
        $campaign = $this->campaign();
        $devotee = DevoteeFactory::new()->create(['name' => 'Pending Payer']);

        $this->offering($campaign, $devotee->id, 1000, minutesAgo: 10);
        $this->offering($campaign, $devotee->id, 9000, minutesAgo: 5, captured: false);

        $rows = CampaignDonors::payload(CampaignDonors::top($campaign->id)->get());

        $this->assertCount(1, $rows);
        $this->assertSame(1000.0, $rows[0]['amount'],
            'a pending/failed payment must not inflate a donor total');
        $this->assertSame(1, $rows[0]['donation_count']);
    }

    /**
     * The anonymity decision, asserted from both ends.
     *
     * A Gupt Daan offering stays in Recent (masked, as it always was) and is
     * kept OUT of Top: it is neither summed into the giver's visible total
     * nor listed as a masked row of its own. If it were summed, the gap
     * between a donor's Top total and their offerings visible in Recent would
     * hand you the secret amount.
     */
    public function test_gupt_daan_is_masked_in_recent_and_absent_from_top(): void
    {
        $campaign = $this->campaign();

        $open = DevoteeFactory::new()->create(['name' => 'Open Donor', 'city' => 'Bhuj']);
        $secret = DevoteeFactory::new()->create(['name' => 'Secret Donor', 'city' => 'Anjar']);

        // A donor with one public and one anonymous offering.
        $this->offering($campaign, $open->id, 2000, minutesAgo: 30);
        $this->offering($campaign, $open->id, 7000, minutesAgo: 20, anonymous: true);

        // A donor who gave anonymously only — the biggest giver overall.
        $this->offering($campaign, $secret->id, 50000, minutesAgo: 10, anonymous: true);

        $top = CampaignDonors::payload(CampaignDonors::top($campaign->id)->get());

        $this->assertCount(1, $top, 'only the public offerings produce a Top row');
        $this->assertSame('Open Donor', $top[0]['name']);
        $this->assertSame(2000.0, $top[0]['amount'],
            'an anonymous offering must not be summed into a visible total — '
            .'the difference would reveal it');
        $this->assertSame(1, $top[0]['donation_count']);

        foreach ($top as $row) {
            $this->assertNotSame(__('projects.gupt_daan_name'), $row['name'],
                'no masked row may appear in Top: adjacent masked totals are '
                .'diffable against the dated masked rows in Recent');
            $this->assertNotSame('Secret Donor', $row['name']);
        }

        // Recent is untouched: every offering, newest first, Gupt Daan masked.
        $recent = CampaignDonors::payload(CampaignDonors::recent($campaign->id)->get());

        $this->assertCount(3, $recent, 'Recent still lists individual offerings');
        $this->assertSame(__('projects.gupt_daan_name'), $recent[0]['name']);
        $this->assertSame('', $recent[0]['city']);
        $this->assertSame(50000.0, $recent[0]['amount']);
        $this->assertSame(1, $recent[0]['donation_count'],
            'a Recent row is one offering, so the new key is always 1 there');
        $this->assertSame(
            ['Secret Donor', 'Open Donor'],
            [$secret->name, $open->name],
            'sanity: the real names exist…'
        );
        $this->assertEmpty(
            array_filter($recent, fn (array $r) => in_array($r['name'], ['Secret Donor'], true)),
            '…and never appear on a public list'
        );
    }

    /**
     * Grouping is on the devotee UUID, never on the displayed name: two
     * people called "Ramesh Patel" are two donors, and one devotee is one
     * donor no matter what their offerings were labelled with.
     */
    public function test_two_devotees_sharing_a_name_are_not_merged(): void
    {
        $campaign = $this->campaign();

        $one = DevoteeFactory::new()->create(['name' => 'Ramesh Patel', 'city' => 'Bhuj']);
        $two = DevoteeFactory::new()->create(['name' => 'Ramesh Patel', 'city' => 'Anjar']);

        $this->offering($campaign, $one->id, 4000, minutesAgo: 30);
        $this->offering($campaign, $two->id, 2500, minutesAgo: 20);
        $this->offering($campaign, $two->id, 2500, minutesAgo: 10);

        $rows = CampaignDonors::payload(CampaignDonors::top($campaign->id)->get());

        $this->assertCount(2, $rows, 'same name, different devotee → two rows');
        $this->assertSame(['Ramesh Patel', 'Ramesh Patel'], array_column($rows, 'name'));
        $this->assertSame([5000.0, 4000.0], array_column($rows, 'amount'));
        $this->assertSame(['Anjar', 'Bhuj'], array_column($rows, 'city'),
            'the ₹5,000 row is the Anjar devotee — the two totals did not merge');
    }

    /**
     * Defensive: the group key is COALESCE(devotee_id, id), so a donation
     * with no devotee attached becomes its own row rather than merging with
     * every other detached donation into one phantom donor.
     *
     * `temple_donations.devotee_id` is currently NOT NULL with a foreign key
     * — there is no way to create such a row today, and no walk-in/manual
     * entry produces one — so this asserts the SQL rather than the data. The
     * moment the column is relaxed (a walk-in cash entry without a devotee),
     * the behaviour is already right.
     */
    public function test_detached_donations_group_on_the_donation_not_on_a_shared_null(): void
    {
        $sql = CampaignDonors::top($this->campaign()->id)->toSql();

        $this->assertStringContainsString(
            'COALESCE(temple_donations.devotee_id, temple_donations.id)',
            $sql,
            'a NULL devotee_id must fall back to the donation id, or every '
            .'detached offering would sum into one phantom donor'
        );
        $this->assertStringContainsString('sum(temple_donations.amount)', strtolower($sql));
    }

    /** Ten donors, not ten donations — the cap is on rows. */
    public function test_the_list_is_capped_at_ten_donors(): void
    {
        $campaign = $this->campaign();

        for ($i = 0; $i < 12; $i++) {
            $devotee = DevoteeFactory::new()->create(['name' => "Donor {$i}"]);
            $this->offering($campaign, $devotee->id, 100.0 * ($i + 1), minutesAgo: 60 - $i);
            $this->offering($campaign, $devotee->id, 100.0 * ($i + 1), minutesAgo: 59 - $i);
        }

        $rows = CampaignDonors::payload(
            CampaignDonors::top($campaign->id)->limit(CampaignDonors::PER_PAGE)->get()
        );

        $this->assertCount(10, $rows);
        $this->assertSame('Donor 11', $rows[0]['name']);
        $this->assertSame(2400.0, $rows[0]['amount']);
        $this->assertSame(2, $rows[0]['donation_count']);
    }
}
