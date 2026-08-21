<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every R2 disk is faked for EVERY test, always (2026-08-21).
     *
     * This project's .env carries real, working Cloudflare R2 credentials —
     * a developer machine and the production VPS point at the SAME buckets.
     * So any test that generates a receipt, an invoice, a greeting card or
     * an uploaded image and forgets `Storage::fake()` does not fail: it
     * quietly writes a real object into the live bucket, named after
     * `fake()->name()`. Hundreds of dummy-English-name PDFs accumulated in
     * temple-private that way before anyone noticed where they came from.
     *
     * Per-test faking was tried first and is what failed. It has to be
     * remembered by every author of every future test, and it was already
     * being half-remembered: several tests faked `r2_private` but not `r2`,
     * or the other way round, so exactly one of the two disks stayed live.
     *
     * Doing it here inverts the default. A test cannot reach the live
     * bucket by omission any more; it would have to deliberately undo this.
     * Individual tests may still call Storage::fake() themselves — a second
     * fake of the same disk is harmless, it just resets the in-memory disk.
     *
     * r2_backup is included for the same reason: `backup:run` writes there,
     * and a test that triggers it should not touch the real backup bucket.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('r2');
        Storage::fake('r2_private');
        Storage::fake('r2_backup');
    }
}
