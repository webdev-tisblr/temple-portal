<?php

declare(strict_types=1);

/**
 * Concurrency worker for tests/Feature/Strict80GTest (defect A).
 *
 * Boots the framework in its OWN process, with its OWN MySQL connection,
 * and takes one 80G receipt serial. Strict80GTest launches several of
 * these at once and asserts every serial came back distinct — which is
 * only meaningful across real processes: an in-process loop would run on
 * a single connection and never exercise the row lock at all.
 *
 * Not a test class and not in a testsuite directory, so PHPUnit never
 * collects it. Never run this by hand against production: it burns a
 * statutory receipt number with no receipt attached to it.
 *
 * argv: <financial_year> <output_file>
 */
$financialYear = $argv[1] ?? null;
$outputFile = $argv[2] ?? null;

if ($financialYear === null || $outputFile === null) {
    fwrite(STDERR, "usage: allocate_receipt_serial.php <financial_year> <output_file>\n");
    exit(2);
}

require __DIR__.'/../../vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    // A tiny random stagger widens the window in which the workers are
    // genuinely inside allocateSerial() together.
    usleep(random_int(0, 40_000));

    $serial = DB::transaction(
        fn (): int => app(\App\Services\ReceiptService::class)->allocateSerial($financialYear),
        5,
    );

    file_put_contents($outputFile, (string) $serial);
    exit(0);
} catch (\Throwable $e) {
    file_put_contents($outputFile, 'ERROR: '.$e->getMessage());
    exit(1);
}
