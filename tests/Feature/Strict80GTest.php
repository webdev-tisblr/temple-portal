<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\Donation80GNotEligibleException;
use App\Models\Donation;
use App\Models\DonationCampaign;
use App\Models\Receipt80G;
use App\Services\ReceiptService;
use Database\Factories\DevoteeFactory;
use Database\Factories\DonationFactory;
use Database\Factories\PaymentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Item 5.4 — STRICT 80G + Gupt Daan, plus the two defects found alongside it.
 *
 * The rule under test, in one line: a donation whose donor has no readable,
 * format-valid PAN NEVER gets an 80G receipt and NEVER burns a receipt
 * number, whatever the amount.
 *
 * ⚠ CORRECTED 2026-08-10 after live testing. The first cut of 5.4 also
 * treated "no PAN" as "Gupt Daan", which quietly stripped ordinary donors
 * off the public donor lists. The two concerns are INDEPENDENT:
 *
 *   80G receipt   ← does the donor hold a valid PAN
 *   Gupt Daan     ← did the donor tick the Gupt Daan checkbox, and nothing else
 *
 * The full {PAN, no PAN} × {ticked, not ticked} matrix is asserted by
 * test_the_four_pan_and_gupt_daan_combinations().
 *
 * This has legal consequences — the trust had been issuing sequentially
 * numbered statutory receipts printing "PAN: N/A" — so the coverage here is
 * deliberately paranoid about the FOUR places that can mint a number:
 *
 *   #1 App\Jobs\Generate80GReceipt              (automatic, on capture)
 *   #2 Filament ViewDonation "Generate 80G Receipt" (admin)
 *   #3 GET /api/v1/donations/{id}/receipt       (the app's download — MINTS)
 *   #4 GET /dashboard/receipts/{receipt}        (web — must be PDF-only)
 *
 * Miss one and the rule leaks.
 *
 * MySQL only. Run against a DEDICATED database, e.g.
 *   DB_DATABASE=temple_portal_test_80g vendor/bin/phpunit --filter Strict80G
 */
class Strict80GTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('r2_private');
    }

    private function service(): ReceiptService
    {
        return app(ReceiptService::class);
    }

    /**
     * @param  array<string, mixed>  $donationAttributes
     */
    private function donationWithoutPan(array $donationAttributes = []): Donation
    {
        return DonationFactory::new()->create(array_merge([
            'devotee_id' => DevoteeFactory::new()->create()->id,
            'payment_id' => PaymentFactory::new()->create()->id,
        ], $donationAttributes));
    }

    /**
     * @param  array<string, mixed>  $donationAttributes
     */
    private function donationWithPan(array $donationAttributes = []): Donation
    {
        return DonationFactory::new()->create(array_merge([
            'devotee_id' => DevoteeFactory::new()->withPan()->create()->id,
            'payment_id' => PaymentFactory::new()->create()->id,
        ], $donationAttributes));
    }

    private function sequenceFor(string $fy): int
    {
        return (int) DB::table('temple_receipt_sequences')
            ->where('financial_year', $fy)
            ->value('last_serial');
    }

    // ── The rule itself ──────────────────────────────────────────────

    public function test_donation_without_pan_gets_no_receipt_and_burns_no_number(): void
    {
        $donation = $this->donationWithoutPan(['amount' => 50000]);

        try {
            $this->service()->generateReceipt($donation);
            $this->fail('a PAN-less donation must not be issued an 80G receipt');
        } catch (Donation80GNotEligibleException $e) {
            $this->assertSame(Donation80GNotEligibleException::REASON_NO_PAN, $e->reason);
        }

        $this->assertSame(0, Receipt80G::count(), 'no Receipt80G row may be created');
        // No counter row at all means not a single number was taken —
        // a stronger assertion than "the counter did not move".
        $this->assertDatabaseMissing('temple_receipt_sequences', [
            'financial_year' => $donation->financial_year,
        ]);
    }

    public function test_a_large_donation_without_pan_is_still_refused(): void
    {
        // The dead ₹2,000 threshold in PanValidationService::isPanRequired()
        // must stay dead: the rule is amount-independent.
        $donation = $this->donationWithoutPan(['amount' => 1000000]);

        $this->assertFalse($this->service()->isEligibleFor80G($donation));
    }

    /**
     * ★ THE CORRECTION (2026-08-10, from live testing).
     *
     * The shipped 5.4 conflated two independent facts and this test is the
     * guard against it coming back: a missing PAN withholds the RECEIPT, it
     * does not make the donation anonymous. Only the donor ticking the Gupt
     * Daan checkbox does that.
     */
    public function test_a_missing_pan_never_makes_a_donation_anonymous(): void
    {
        $donation = $this->donationWithoutPan(['anonymous' => false, 'is_80g_eligible' => true]);

        try {
            $this->service()->generateReceipt($donation);
        } catch (Donation80GNotEligibleException) {
            // expected — no receipt
        }

        $fresh = $donation->fresh();
        $this->assertFalse(
            (bool) $fresh->anonymous,
            'no PAN means no 80G receipt — it must NOT flip the donor to Gupt Daan',
        );
        $this->assertFalse($fresh->is_80g_eligible, 'is_80g_eligible must state the real verdict');
    }

    /**
     * The other half of the correction: anonymity is about public display,
     * not about tax documents. A donor who chose Gupt Daan AND holds a
     * valid PAN still gets their statutory 80G receipt.
     */
    public function test_a_gupt_daan_donor_with_a_pan_still_gets_their_80g_receipt(): void
    {
        $donation = $this->donationWithPan(['anonymous' => true]);

        $receipt = $this->service()->generateReceipt($donation);

        $this->assertSame($donation->id, $receipt->donation_id);
        $this->assertSame('ABCDE1234F', $receipt->pan_number);
        $this->assertSame(1, $this->sequenceFor($donation->financial_year), 'a number IS burned');
        // …and the choice is untouched by the receipt pipeline.
        $this->assertTrue((bool) $donation->fresh()->anonymous);
    }

    public function test_gupt_daan_still_keeps_every_donor_detail(): void
    {
        // Anonymity is a PUBLIC-DISPLAY choice, never a data-collection one:
        // the trust must still be able to see who donated. `anonymous` is
        // set here explicitly, because that is the ONLY way it is ever set —
        // the donor ticked the box.
        $devotee = DevoteeFactory::new()->create([
            'name' => 'Kishor Bhai',
            'email' => 'kishor@example.test',
            'city' => 'Gandhidham',
        ]);
        $donation = DonationFactory::new()->create([
            'devotee_id' => $devotee->id,
            'payment_id' => PaymentFactory::new()->create()->id,
            'anonymous' => true,
        ]);

        try {
            $this->service()->generateReceipt($donation);
        } catch (Donation80GNotEligibleException) {
            // expected
        }

        $fresh = $donation->fresh()->load('devotee');
        $this->assertTrue($fresh->anonymous);
        $this->assertSame($devotee->id, $fresh->devotee_id);
        $this->assertSame('Kishor Bhai', $fresh->devotee->name);
        $this->assertSame('kishor@example.test', $fresh->devotee->email);
        $this->assertSame('Gandhidham', $fresh->devotee->city);
    }

    /**
     * ★ The full truth table, end to end, in one place.
     *
     *   {valid PAN, no PAN} × {Gupt Daan ticked, not ticked}
     *
     * asserting for each combination: is a receipt issued, is a statutory
     * number burned, and is the donor masked on the PUBLIC donor list
     * (which is what CampaignDonors::payload drives on web AND app).
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('panAndAnonymityCombinations')]
    public function test_the_four_pan_and_gupt_daan_combinations(
        bool $hasPan,
        bool $guptDaan,
        bool $expectReceipt,
        bool $expectMasked,
    ): void {
        $campaign = DonationCampaign::create([
            'title_gu' => 'સંયોજન પરીક્ષણ',
            'title_en' => 'Combination Test',
            'slug' => 'combo-'.uniqid(),
            'goal_amount' => 100000,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'is_active' => true,
        ]);

        $devoteeFactory = DevoteeFactory::new();
        if ($hasPan) {
            $devoteeFactory = $devoteeFactory->withPan();
        }
        $devotee = $devoteeFactory->create(['name' => 'Ramesh Patel', 'city' => 'Bhuj']);

        $donation = DonationFactory::new()->create([
            'devotee_id' => $devotee->id,
            'payment_id' => PaymentFactory::new()->captured()->create()->id,
            'donation_type' => 'campaign',
            'campaign_id' => $campaign->id,
            'anonymous' => $guptDaan,
            'wants_80g' => true,
        ]);

        // Drive it through the REAL automatic path, not the service alone.
        (new \App\Jobs\Generate80GReceipt($donation))->handle($this->service());

        if ($expectReceipt) {
            $this->assertSame(1, Receipt80G::where('donation_id', $donation->id)->count(),
                'a receipt was expected for this combination');
            $this->assertSame(1, $this->sequenceFor($donation->financial_year),
                'a statutory number must be burned when a receipt is issued');
            $this->assertTrue((bool) $donation->fresh()->receipt_generated);
        } else {
            $this->assertSame(0, Receipt80G::count(), 'no receipt may exist for this combination');
            $this->assertDatabaseMissing('temple_receipt_sequences', [
                'financial_year' => $donation->financial_year,
            ]);
            $this->assertFalse((bool) $donation->fresh()->receipt_generated);
        }

        // Anonymity is never a side effect — it is exactly what was chosen.
        $this->assertSame($guptDaan, (bool) $donation->fresh()->anonymous);

        // …and the public donor list agrees.
        $row = \App\Support\CampaignDonors::payload(
            \App\Support\CampaignDonors::recent($campaign->id)->get()
        )[0];

        if ($expectMasked) {
            $this->assertSame(__('projects.gupt_daan_name'), $row['name']);
            $this->assertSame('', $row['city']);
        } else {
            $this->assertSame('Ramesh Patel', $row['name'],
                'a donor who did not choose Gupt Daan must appear by name');
            $this->assertSame('Bhuj', $row['city']);
        }
    }

    /**
     * @return array<string, array{bool, bool, bool, bool}>
     */
    public static function panAndAnonymityCombinations(): array
    {
        //                                    hasPan  guptDaan  receipt  masked
        return [
            'PAN + not ticked'          => [true,   false,    true,    false],
            'PAN + Gupt Daan ticked'    => [true,   true,     true,    true],
            'no PAN + not ticked'       => [false,  false,    false,   false],
            'no PAN + Gupt Daan ticked' => [false,  true,     false,   true],
        ];
    }

    public function test_donation_with_a_valid_pan_gets_a_receipt_and_takes_a_number(): void
    {
        $donation = $this->donationWithPan();

        $receipt = $this->service()->generateReceipt($donation);

        $this->assertSame($donation->id, $receipt->donation_id);
        $this->assertSame('ABCDE1234F', $receipt->pan_number);
        $this->assertStringContainsString($donation->financial_year, $receipt->receipt_number);
        $this->assertStringEndsWith('00001', $receipt->receipt_number);
        $this->assertSame(1, $this->sequenceFor($donation->financial_year));
        $this->assertTrue($donation->fresh()->is_80g_eligible);
        $this->assertNotNull($receipt->pdf_path);
        $this->assertTrue(Storage::disk('r2_private')->exists($receipt->pdf_path));
    }

    public function test_a_garbage_pan_is_treated_as_no_pan(): void
    {
        // Encrypted fine, but not a PAN. Under a strict rule this must not
        // reach a statutory document.
        $donation = DonationFactory::new()->create([
            'devotee_id' => DevoteeFactory::new()->withPan('NOTAPAN12')->create()->id,
            'payment_id' => PaymentFactory::new()->create()->id,
        ]);

        try {
            $this->service()->generateReceipt($donation);
            $this->fail('an invalid PAN must not produce a receipt');
        } catch (Donation80GNotEligibleException $e) {
            $this->assertSame(Donation80GNotEligibleException::REASON_INVALID_PAN, $e->reason);
        }

        $this->assertSame(0, Receipt80G::count());
    }

    public function test_an_unreadable_pan_is_not_eligible(): void
    {
        // Simulates an APP_KEY rotation. The old code swallowed the decrypt
        // failure and printed "PAN: N/A" on a numbered statutory receipt.
        $donation = $this->donationWithoutPan();
        $donation->devotee->update([
            'pan_encrypted' => 'this-is-not-a-valid-laravel-ciphertext',
            'pan_last_four' => '1234',
        ]);

        try {
            $this->service()->generateReceipt($donation->fresh());
            $this->fail('an undecryptable PAN must not produce a receipt');
        } catch (Donation80GNotEligibleException $e) {
            $this->assertSame(Donation80GNotEligibleException::REASON_PAN_UNREADABLE, $e->reason);
        }

        $this->assertSame(0, Receipt80G::count());
    }

    public function test_no_receipt_row_ever_carries_the_literal_na_pan(): void
    {
        // The exact legal defect item 5.4 exists to close.
        foreach ([$this->donationWithoutPan(), $this->donationWithPan()] as $donation) {
            try {
                $this->service()->generateReceipt($donation);
            } catch (Donation80GNotEligibleException) {
                // expected for the PAN-less one
            }
        }

        $this->assertSame(0, Receipt80G::whereIn('pan_number', ['N/A', ''])->count());
        $this->assertSame(0, Receipt80G::where('pan_number', 'like', '******%')->count());
    }

    public function test_a_donor_who_opted_out_of_80g_gets_no_receipt(): void
    {
        $donation = $this->donationWithPan(['wants_80g' => false]);

        try {
            $this->service()->generateReceipt($donation);
            $this->fail('wants_80g = false must not produce a receipt');
        } catch (Donation80GNotEligibleException $e) {
            $this->assertSame(Donation80GNotEligibleException::REASON_NOT_REQUESTED, $e->reason);
        }

        // Opting out is NOT the same as having no PAN — this donor keeps
        // their name on the public donor list.
        $this->assertFalse($donation->fresh()->anonymous);
        $this->assertSame(0, Receipt80G::count());
    }

    // ── All four allocation sites ────────────────────────────────────

    /** Site #1 — the automatic job on payment capture. */
    public function test_site_1_auto_job_skips_a_pan_less_donation_without_failing(): void
    {
        $donation = $this->donationWithoutPan();

        // Must NOT throw: a Gupt Daan is a normal outcome, not an error,
        // and a throwing job would be retried three times and then alarm.
        (new \App\Jobs\Generate80GReceipt($donation))->handle($this->service());

        $this->assertSame(0, Receipt80G::count());
        $this->assertFalse((bool) $donation->fresh()->receipt_generated);

        $withPan = $this->donationWithPan();
        (new \App\Jobs\Generate80GReceipt($withPan))->handle($this->service());

        $this->assertSame(1, Receipt80G::count());
        $this->assertTrue((bool) $withPan->fresh()->receipt_generated);
    }

    /** Site #2 — the admin "Generate 80G Receipt" button. */
    public function test_site_2_admin_action_is_hidden_and_guarded(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/DonationResource/Pages/ViewDonation.php'));

        // The button must not be offered for an ineligible donation…
        $this->assertStringContainsString('isEligibleFor80G($this->record)', $source);
        // …and the action must still handle the race where the donor
        // cleared their PAN after the page rendered.
        $this->assertStringContainsString('Donation80GNotEligibleException', $source);
        // The download action must never be a minting path.
        $this->assertStringContainsString('ensurePdf($receipt)', $source);

        // And the gate the button consults really does refuse.
        $this->assertFalse($this->service()->isEligibleFor80G($this->donationWithoutPan()));
        $this->assertTrue($this->service()->isEligibleFor80G($this->donationWithPan()));
    }

    /** Site #3 — the app's receipt download, which mints rows. */
    public function test_site_3_app_receipt_endpoint_refuses_without_minting(): void
    {
        $devotee = DevoteeFactory::new()->create();
        $payment = PaymentFactory::new()->create(['status' => 'captured']);
        $donation = DonationFactory::new()->create([
            'devotee_id' => $devotee->id,
            'payment_id' => $payment->id,
        ]);

        $response = $this->actingAs($devotee, 'sanctum')
            ->getJson("/api/v1/donations/{$donation->id}/receipt");

        // 404, not 500: there is nothing to fetch and never will be.
        $response->assertStatus(404);
        $this->assertSame(0, Receipt80G::count(), 'the app download must not mint a receipt row');
        $this->assertDatabaseMissing('temple_receipt_sequences', [
            'financial_year' => $donation->financial_year,
        ]);

        // The message must be a sentence a donor can act on, not a stack
        // trace — the app renders a download failure as a crash.
        $this->assertNotEmpty($response->json('message'));
        $this->assertStringNotContainsString('Exception', (string) $response->json('message'));
    }

    /** Site #4 — the web dashboard download, which must be PDF-only. */
    public function test_site_4_web_dashboard_download_cannot_allocate(): void
    {
        $devotee = DevoteeFactory::new()->withPan()->create();
        $donation = DonationFactory::new()->create([
            'devotee_id' => $devotee->id,
            'payment_id' => PaymentFactory::new()->create(['status' => 'captured'])->id,
        ]);
        $receipt = $this->service()->generateReceipt($donation);

        $numberBefore = $receipt->receipt_number;
        $sequenceBefore = $this->sequenceFor($donation->financial_year);

        // Nightly sweep behaviour: the file is gone, pdf_path is NULLed.
        Storage::disk('r2_private')->delete($receipt->pdf_path);
        $receipt->update(['pdf_path' => null]);

        $this->actingAs($devotee, 'devotee')
            ->get("/dashboard/receipts/{$receipt->id}/download")
            ->assertRedirect();

        $regenerated = $receipt->fresh();
        $this->assertNotNull($regenerated->pdf_path, 'the PDF must self-heal');
        $this->assertSame($numberBefore, $regenerated->receipt_number, 'the number must not change');
        $this->assertSame(1, Receipt80G::count());
        $this->assertSame($sequenceBefore, $this->sequenceFor($donation->financial_year),
            'a PDF refresh must not advance the statutory sequence');
    }

    // ── Creation sites set the verdict honestly ──────────────────────

    public function test_creation_sites_record_the_80g_verdict_rather_than_hardcoding_it(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Api/V1/DonationController.php'));
        $this->assertStringNotContainsString("'is_80g_eligible' => true", $source,
            'is_80g_eligible must be a computed verdict, never hardcoded');

        $web = file_get_contents(app_path('Http/Controllers/Web/DonationWebController.php'));
        $this->assertStringNotContainsString("'is_80g_eligible' => true", $web);
    }

    /**
     * ★ Structural guard for the 2026-08-10 correction. Every site that
     * writes `anonymous` must read it from the donor's checkbox ALONE.
     * The shipped code derived it from the PAN with `|| ! $hasValidPan`,
     * which silently un-named ordinary donors on the public lists.
     */
    public function test_no_creation_site_derives_anonymity_from_the_pan(): void
    {
        $files = [
            app_path('Http/Controllers/Api/V1/DonationController.php'),
            app_path('Http/Controllers/Web/DonationWebController.php'),
            app_path('Services/CounterEntryService.php'),
            app_path('Services/ReceiptService.php'),
        ];

        foreach ($files as $file) {
            $source = file_get_contents($file);

            // Strip comments: the files deliberately DISCUSS the old
            // expression so it is never reintroduced by accident.
            $code = implode('', array_map(
                function (array|string $token): string {
                    if (! is_array($token)) {
                        return $token;
                    }

                    return in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1];
                },
                token_get_all($source),
            ));

            // The `$anonymous = …;` assignment must mention the donor's
            // own field and nothing about the PAN. (`! $hasValidPan` is
            // still legitimate elsewhere — it is what triggers the web
            // PAN interstitial.)
            preg_match_all('/\$anonymous\s*=\s*([^;]+);/', $code, $matches);
            foreach ($matches[1] as $expression) {
                $this->assertStringNotContainsString('hasValidPan', $expression,
                    basename($file).' must not derive anonymity from the PAN');
                $this->assertStringContainsString("'anonymous'", $expression,
                    basename($file).' must read anonymity from the donor’s own checkbox');
            }

            $this->assertStringNotContainsString("\$updates['anonymous']", $code,
                basename($file).' must not write anonymous outside the donor’s own choice');
        }
    }

    // ── Defect A — the receipt-number allocator ──────────────────────

    public function test_a_deleted_receipt_never_hands_its_number_back(): void
    {
        // The old COUNT(*)+1 scheme re-issued a burnt number after any
        // deletion — and then died on the UNIQUE index.
        $first = $this->service()->generateReceipt($this->donationWithPan());
        $this->assertStringEndsWith('00001', $first->receipt_number);

        $first->delete();

        $second = $this->service()->generateReceipt($this->donationWithPan());

        $this->assertNotSame($first->receipt_number, $second->receipt_number);
        $this->assertStringEndsWith('00002', $second->receipt_number, 'gaps are fine; reuse is not');
    }

    public function test_serials_are_independent_per_financial_year(): void
    {
        $a = $this->service()->generateReceipt($this->donationWithPan(['financial_year' => '2026-27']));
        $b = $this->service()->generateReceipt($this->donationWithPan(['financial_year' => '2027-28']));

        $this->assertStringEndsWith('00001', $a->receipt_number);
        $this->assertStringEndsWith('00001', $b->receipt_number);
        $this->assertNotSame($a->receipt_number, $b->receipt_number);
    }

    /**
     * DEFECT A, the real proof: parallel PROCESSES, each on its own MySQL
     * connection, all allocating in the same financial year at once.
     *
     * An in-process loop would share one connection and never exercise the
     * row lock, so this shells out to tests/Support/allocate_receipt_serial.php.
     * The workers write their serial to a file, which is also why the
     * assertion needs no read from the parent's (transaction-wrapped)
     * connection.
     */
    public function test_concurrent_allocations_cannot_collide(): void
    {
        $workers = 8;
        // Unique FY per run: the workers COMMIT on their own connections,
        // so their rows outlive this test's RefreshDatabase transaction.
        $fy = '2'.substr((string) random_int(100, 999), 0, 3).'-99';

        $dir = sys_get_temp_dir().'/receipt-seq-'.uniqid();
        mkdir($dir, 0777, true);

        $script = base_path('tests/Support/allocate_receipt_serial.php');
        $this->assertFileExists($script);

        $env = [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => getenv('HOME') ?: sys_get_temp_dir(),
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => config('database.connections.mysql.database'),
            'DB_HOST' => (string) config('database.connections.mysql.host'),
            'DB_PORT' => (string) config('database.connections.mysql.port'),
            'DB_USERNAME' => (string) config('database.connections.mysql.username'),
            'DB_PASSWORD' => (string) config('database.connections.mysql.password'),
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
        ];

        $processes = [];
        for ($i = 0; $i < $workers; $i++) {
            $processes[$i] = proc_open(
                [PHP_BINARY, $script, $fy, "{$dir}/{$i}.txt"],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes[$i],
                base_path(),
                $env,
            );
            $this->assertIsResource($processes[$i], 'could not spawn allocation worker');
        }

        $stderr = [];
        foreach ($processes as $i => $process) {
            $stderr[$i] = stream_get_contents($pipes[$i][2]);
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            proc_close($process);
        }

        $serials = [];
        for ($i = 0; $i < $workers; $i++) {
            $file = "{$dir}/{$i}.txt";
            $this->assertFileExists($file, "worker {$i} produced nothing. stderr: ".($stderr[$i] ?? ''));
            $value = trim((string) file_get_contents($file));
            $this->assertMatchesRegularExpression('/^\d+$/', $value,
                "worker {$i} failed to allocate: {$value}");
            $serials[] = (int) $value;
            @unlink($file);
        }
        @rmdir($dir);

        sort($serials);

        $this->assertCount($workers, $serials);
        $this->assertSame($serials, array_values(array_unique($serials)),
            'two concurrent allocations returned the SAME receipt serial: '.implode(',', $serials));
        // Contiguous 1..N — nothing was skipped and nothing double-taken.
        $this->assertSame(range(1, $workers), $serials);

        DB::table('temple_receipt_sequences')->where('financial_year', $fy)->delete();
    }

    // ── Defect B — campaign title is a snapshot, not a live read ─────

    public function test_renaming_a_campaign_does_not_rewrite_an_issued_receipt(): void
    {
        $campaign = DonationCampaign::create([
            'title_gu' => 'ગૌશાળા નિર્માણ',
            'title_hi' => 'गौशाला निर्माण',
            'title_en' => 'Gaushala Construction',
            'slug' => 'gaushala-'.uniqid(),
            'goal_amount' => 100000,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'is_active' => true,
        ]);

        $donation = $this->donationWithPan([
            'donation_type' => 'campaign',
            'campaign_id' => $campaign->id,
            'purpose' => 'Cow shed roofing',
        ]);

        $receipt = $this->service()->generateReceipt($donation);

        $this->assertSame('Gaushala Construction', $receipt->campaign_title);
        $this->assertSame('Cow shed roofing', $receipt->donation_purpose);

        // Admin renames the campaign a month later…
        $campaign->update(['title_en' => 'Gaushala Renovation Phase 2']);
        // …and the nightly sweep drops the cached PDF, forcing a re-render.
        Storage::disk('r2_private')->delete($receipt->pdf_path);
        $receipt->update(['pdf_path' => null]);

        $regenerated = $this->service()->ensurePdf($receipt->fresh());

        $this->assertNotNull($regenerated->pdf_path);
        $this->assertSame('Gaushala Construction', $regenerated->campaign_title,
            'the snapshot must survive the rename');
        $this->assertSame(
            'Gaushala Construction',
            $this->service()->campaignTitleFor($regenerated),
            'the re-rendered receipt must print the ORIGINAL campaign name',
        );
    }

    public function test_legacy_receipts_without_a_snapshot_fall_back_to_the_live_relation(): void
    {
        $campaign = DonationCampaign::create([
            'title_gu' => 'અન્નદાન',
            'title_en' => 'Annadan Drive',
            'slug' => 'annadan-'.uniqid(),
            'goal_amount' => 50000,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'is_active' => true,
        ]);

        $donation = $this->donationWithPan([
            'donation_type' => 'campaign',
            'campaign_id' => $campaign->id,
        ]);
        $receipt = $this->service()->generateReceipt($donation);

        // A row issued before the snapshot column existed.
        $receipt->update(['campaign_title' => null]);

        $this->assertSame('Annadan Drive', $this->service()->campaignTitleFor($receipt->fresh()));
    }

    // ── PAN handling: optional, clearable, never leaked ──────────────

    public function test_a_devotee_can_clear_a_wrong_pan_via_the_web_profile(): void
    {
        $devotee = DevoteeFactory::new()->withPan('WRONG1234Z')->create();
        $this->assertNotNull($devotee->pan_encrypted);

        $this->actingAs($devotee, 'devotee')
            ->put('/dashboard/profile', [
                'name' => $devotee->name,
                'clear_pan' => 1,
            ])
            ->assertRedirect();

        $fresh = $devotee->fresh();
        $this->assertNull($fresh->pan_encrypted, 'a wrong PAN must be removable');
        $this->assertNull($fresh->pan_last_four);
    }

    public function test_a_devotee_can_clear_a_wrong_pan_via_the_api(): void
    {
        $devotee = DevoteeFactory::new()->withPan()->create();

        $this->actingAs($devotee, 'sanctum')
            ->putJson('/api/v1/me', ['clear_pan' => true])
            ->assertOk()
            ->assertJsonPath('data.has_pan', false)
            ->assertJsonPath('data.pan_last_four', null);

        $this->assertNull($devotee->fresh()->pan_encrypted);
    }

    public function test_an_unrelated_profile_save_does_not_wipe_the_pan(): void
    {
        // The PAN field submits blank whenever it isn't being changed, so
        // "blank means clear" would have been a data-loss bug.
        $devotee = DevoteeFactory::new()->withPan()->create();

        $this->actingAs($devotee, 'devotee')
            ->put('/dashboard/profile', [
                'name' => 'Renamed Devotee',
                'pan_number' => '',
            ])
            ->assertRedirect();

        $this->assertNotNull($devotee->fresh()->pan_encrypted);
    }

    public function test_the_pan_is_never_exposed_by_the_api(): void
    {
        $devotee = DevoteeFactory::new()->withPan('QWERT5678Y')->create();

        $body = $this->actingAs($devotee, 'sanctum')->getJson('/api/v1/me')->getContent();

        $this->assertStringNotContainsString('QWERT5678Y', $body);
        $this->assertStringNotContainsString('pan_encrypted', $body);
        // Only the two agreed signals travel.
        $this->assertStringContainsString('has_pan', $body);
        $this->assertStringContainsString('678Y', $body);
    }

    // ── The web checkout prompt (mandatory: iOS donates via the web) ──

    public function test_checkout_bounces_a_pan_less_donor_to_the_profile_and_remembers_the_donation(): void
    {
        $devotee = DevoteeFactory::new()->create(['name' => 'Jayesh']);

        $response = $this->actingAs($devotee, 'devotee')->post('/donate', [
            'amount' => 2100,
            'donation_type' => 'general',
            'purpose' => 'Annadan seva',
            'wants_80g' => 1,
        ]);

        $response->assertRedirect(route('dashboard.profile'));
        $response->assertSessionHas('pan_required_for_80g');

        // Nothing was charged and nothing was created.
        $this->assertSame(0, Donation::count());

        // …and the return destination carries the half-finished donation
        // back, using the SafeRedirect mechanism from item 3.1.
        $intended = session('url.intended');
        $this->assertNotNull($intended, 'the donation must be remembered as the return destination');
        $this->assertStringContainsString('/donate', $intended);
        $this->assertStringContainsString('amount=2100', $intended);
        $this->assertStringContainsString('Annadan', rawurldecode($intended));
    }

    public function test_saving_a_pan_returns_the_donor_to_their_donation(): void
    {
        $devotee = DevoteeFactory::new()->create(['name' => 'Jayesh']);

        $this->actingAs($devotee, 'devotee')->post('/donate', [
            'amount' => 2100,
            'donation_type' => 'general',
            'wants_80g' => 1,
        ])->assertRedirect(route('dashboard.profile'));

        $this->actingAs($devotee, 'devotee')
            ->put('/dashboard/profile', [
                'name' => 'Jayesh',
                'pan_number' => 'ABCDE1234F',
            ])
            ->assertRedirectContains('/donate');

        $this->assertTrue($this->service()->devoteeHasValid80GPan($devotee->fresh()));
    }

    public function test_a_donor_who_declines_80g_is_not_bounced(): void
    {
        $devotee = DevoteeFactory::new()->create(['name' => 'Jayesh']);

        // wants_80g = 0 → straight through as a Gupt Daan, no interstitial.
        $response = $this->actingAs($devotee, 'devotee')->post('/donate', [
            'amount' => 501,
            'donation_type' => 'general',
            'wants_80g' => 0,
        ]);

        $response->assertSessionMissing('pan_required_for_80g');
    }

    public function test_the_donate_page_shows_the_80g_prompt_for_a_pan_less_devotee(): void
    {
        $devotee = DevoteeFactory::new()->create(['name' => 'Jayesh']);

        $html = $this->actingAs($devotee, 'devotee')->get('/donate')->assertOk()->getContent();

        $this->assertStringContainsString(__('donation.want_80g'), $html);
        $this->assertStringContainsString(__('donation.pan_required_title'), $html);
        $this->assertStringContainsString(__('donation.add_pan_now'), $html);
        $this->assertStringContainsString(__('donation.continue_without_80g'), $html);

        // The Gupt Daan checkbox is a SEPARATE control with its own
        // explanation — the correction from live testing was that donors
        // could not tell what it did or that it was independent of 80G.
        $this->assertStringContainsString(__('donation.gupt_daan'), $html);
        $this->assertStringContainsString(__('donation.gupt_daan_hint'), $html);
        $this->assertStringContainsString('name="anonymous"', $html);
        $this->assertStringContainsString('name="wants_80g"', $html);

        // A devotee who already has a PAN sees the checkbox but not the prompt.
        $withPan = DevoteeFactory::new()->withPan()->create(['name' => 'Bhavna']);
        $html = $this->actingAs($withPan, 'devotee')->get('/donate')->assertOk()->getContent();

        $this->assertStringContainsString(__('donation.want_80g'), $html);
        $this->assertStringNotContainsString(__('donation.pan_required_title'), $html);
    }

    /**
     * The campaign page sidebar is a SECOND donate surface posting to the
     * same controller. Before this it carried only the Gupt Daan box and no
     * 80G checkbox, so a PAN-less donor was bounced to their profile with
     * no way to say "I don't need the receipt".
     */
    public function test_the_campaign_page_carries_both_checkboxes_independently(): void
    {
        $campaign = DonationCampaign::create([
            'title_gu' => 'મંદિર જીર્ણોદ્ધાર',
            'title_en' => 'Temple Restoration',
            'slug' => 'restoration-'.uniqid(),
            'goal_amount' => 500000,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'is_active' => true,
        ]);

        $devotee = DevoteeFactory::new()->create(['name' => 'Jayesh']);

        $html = $this->actingAs($devotee, 'devotee')
            ->get("/projects/{$campaign->slug}")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(__('donation.gupt_daan'), $html);
        $this->assertStringContainsString(__('donation.gupt_daan_hint'), $html);
        $this->assertStringContainsString(__('donation.want_80g'), $html);
        $this->assertStringContainsString('name="anonymous"', $html);
        $this->assertStringContainsString('name="wants_80g"', $html);
        // The escape hatch, so no donor is trapped in the PAN bounce.
        $this->assertStringContainsString(__('donation.continue_without_80g'), $html);
    }

    /**
     * The 80G receipt must carry the donor's FULL PAN.
     *
     * It is a statutory document the donor files with their return, and a
     * masked "******211R" is useless to an assessing officer. Receipts issued
     * before 2026-08-09 stored a masked value; this locks the current
     * behaviour so it cannot regress back.
     */
    public function test_the_receipt_stores_the_full_pan_not_a_masked_one(): void
    {
        $devotee = DevoteeFactory::new()->withPan('ABCDE1234F')->create();
        $payment = PaymentFactory::new()->create(['status' => 'captured']);
        $donation = DonationFactory::new()->create([
            'devotee_id' => $devotee->id,
            'payment_id' => $payment->id,
            'amount' => 5000,
            'wants_80g' => true,
        ]);

        $receipt = app(\App\Services\ReceiptService::class)->generateReceipt($donation);

        $this->assertSame('ABCDE1234F', $receipt->pan_number);
        $this->assertStringNotContainsString('*', $receipt->pan_number);
    }
}
