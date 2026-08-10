<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Minimal, dependency-free QR Code (Model 2) encoder — byte mode, error
 * correction level M, versions 1–10 (up to 213 bytes of payload).
 *
 * WHY THIS EXISTS
 * ---------------
 * The login page shows scannable Play Store / App Store links. The three
 * alternatives were all worse:
 *   • a third-party QR web service (api.qrserver.com &co) — leaks every
 *     visitor's request to a third party, breaks under our CSP, and dies
 *     silently the day that service does;
 *   • `composer require` a QR library — a new production dependency, and a
 *     deploy-time composer install, for ~300 lines of well-specified maths;
 *   • a pre-rendered PNG committed to the repo — goes stale the moment an
 *     admin edits the store URL in System Settings.
 *
 * Output is an inline <svg> string: no HTTP request, no image asset, scales
 * cleanly at any size, and costs nothing to serve. Generation is cheap
 * (~1ms) but cached anyway — see {@see cachedSvg()}.
 *
 * SCOPE / LIMITS (deliberate)
 * ---------------------------
 * Byte mode + level M only. Versions 1–10 cover 213 bytes, which is far more
 * than any store listing URL. Anything longer returns null and the caller is
 * expected to degrade gracefully (hide the QR, keep the plain link) rather
 * than render a broken code. Numeric/alphanumeric compaction is not
 * implemented: it would only shrink the symbol, never change correctness.
 *
 * Verified module-for-module (version, mask and every module) against the
 * reference `qrcode` npm implementation across the full 1–213 byte range.
 */
final class QrCode
{
    /** Error-correction level M — the 2-bit code used in the format string. */
    private const EC_LEVEL_BITS = 0b00;

    /**
     * version => [ec codewords per block, [[block count, data codewords], …]]
     * Level M only. (ISO/IEC 18004 table 9.)
     */
    private const BLOCKS = [
        1 => [10, [[1, 16]]],
        2 => [16, [[1, 28]]],
        3 => [26, [[1, 44]]],
        4 => [18, [[2, 32]]],
        5 => [24, [[2, 43]]],
        6 => [16, [[4, 27]]],
        7 => [18, [[4, 31]]],
        8 => [22, [[2, 38], [2, 39]]],
        9 => [22, [[3, 36], [2, 37]]],
        10 => [26, [[4, 43], [1, 44]]],
    ];

    /** Alignment-pattern centre coordinates per version. */
    private const ALIGNMENT = [
        1 => [],
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
        7 => [6, 22, 38],
        8 => [6, 24, 42],
        9 => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    /** @var array<int,int>|null GF(256) antilog table, lazily built. */
    private static ?array $gfExp = null;

    /** @var array<int,int>|null GF(256) log table, lazily built. */
    private static ?array $gfLog = null;

    /**
     * Cached inline SVG for a payload. The cache key hashes the payload, so
     * an admin editing the store URL in System Settings simply lands on a
     * different key — there is no cache to bust.
     */
    public static function cachedSvg(string $text, int $quietZone = 2, string $dark = '#2A1810'): ?string
    {
        if (trim($text) === '') {
            return null;
        }

        $key = 'qr.svg.v1.'.sha1($text.'|'.$quietZone.'|'.$dark);

        // Cache::remember() cannot cache null (a null hit looks like a miss
        // and regenerates every request), so an unencodable payload is
        // stored as the sentinel '' and translated back on read.
        $svg = Cache::remember($key, now()->addDays(30), function () use ($text, $quietZone, $dark) {
            return self::svg($text, $quietZone, $dark) ?? '';
        });

        return $svg === '' ? null : $svg;
    }

    /**
     * Render $text as an inline SVG string, or null if it cannot be encoded
     * within versions 1–10. The SVG has no width/height — it fills its
     * container via viewBox, so the caller sizes it with CSS.
     */
    public static function svg(string $text, int $quietZone = 2, string $dark = '#2A1810'): ?string
    {
        $matrix = self::matrix($text);

        if ($matrix === null) {
            return null;
        }

        $size = count($matrix);
        $span = $size + 2 * $quietZone;

        // One <path> of horizontal runs — an order of magnitude smaller than
        // a <rect> per module, and renders identically.
        $d = '';
        for ($row = 0; $row < $size; $row++) {
            $col = 0;
            while ($col < $size) {
                if ($matrix[$row][$col] !== 1) {
                    $col++;

                    continue;
                }
                $run = 0;
                while ($col + $run < $size && $matrix[$row][$col + $run] === 1) {
                    $run++;
                }
                $d .= 'M'.($col + $quietZone).' '.($row + $quietZone).'h'.$run.'v1h-'.$run.'z';
                $col += $run;
            }
        }

        // The inline style is deliberate: an <svg> with only a viewBox falls
        // back to the replaced-element default of 300×150 in every browser,
        // so the code would render squashed inside its tile.
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$span.' '.$span.'"'
            .' style="display:block;width:100%;height:auto"'
            .' shape-rendering="crispEdges" focusable="false" aria-hidden="true">'
            .'<path fill="'.e($dark).'" d="'.$d.'"/>'
            .'</svg>';
    }

    /**
     * The module matrix: `$matrix[row][col]`, 1 = dark. Null when the
     * payload exceeds version 10 at level M.
     *
     * @return array<int,array<int,int>>|null
     */
    public static function matrix(string $text): ?array
    {
        $length = strlen($text);

        $version = null;
        $dataCodewords = 0;
        $countBits = 8;

        foreach (self::BLOCKS as $candidate => [$ecPerBlock, $groups]) {
            $capacity = 0;
            foreach ($groups as [$count, $perBlock]) {
                $capacity += $count * $perBlock;
            }
            // Version 10 is the first to use a 16-bit character count.
            $bits = $candidate < 10 ? 8 : 16;

            if ($length <= intdiv($capacity * 8 - 4 - $bits, 8)) {
                $version = $candidate;
                $dataCodewords = $capacity;
                $countBits = $bits;
                break;
            }
        }

        if ($version === null) {
            return null;
        }

        $codewords = self::encodeCodewords($text, $version, $dataCodewords, $countBits);

        return self::buildMatrix($version, $codewords);
    }

    // ── Data encoding ────────────────────────────────────────────────────

    /**
     * Byte-mode bit stream → padded data codewords → per-block Reed-Solomon
     * → interleaved final codeword sequence.
     *
     * @return list<int>
     */
    private static function encodeCodewords(string $text, int $version, int $dataCodewords, int $countBits): array
    {
        $bits = '0100'; // byte mode
        $bits .= str_pad(decbin(strlen($text)), $countBits, '0', STR_PAD_LEFT);

        for ($i = 0, $n = strlen($text); $i < $n; $i++) {
            $bits .= str_pad(decbin(ord($text[$i])), 8, '0', STR_PAD_LEFT);
        }

        $capacityBits = $dataCodewords * 8;

        // Terminator (up to four zeros), then pad to a byte boundary.
        $bits .= str_repeat('0', min(4, $capacityBits - strlen($bits)));
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - strlen($bits) % 8);
        }

        // Alternating pad codewords 0xEC / 0x11 fill the remainder.
        $pad = ['11101100', '00010001'];
        $i = 0;
        while (strlen($bits) < $capacityBits) {
            $bits .= $pad[$i++ % 2];
        }

        $data = [];
        for ($i = 0; $i < $capacityBits; $i += 8) {
            $data[] = bindec(substr($bits, $i, 8));
        }

        [$ecPerBlock, $groups] = self::BLOCKS[$version];

        $dataBlocks = [];
        $ecBlocks = [];
        $offset = 0;

        foreach ($groups as [$count, $perBlock]) {
            for ($b = 0; $b < $count; $b++) {
                $block = array_slice($data, $offset, $perBlock);
                $offset += $perBlock;
                $dataBlocks[] = $block;
                $ecBlocks[] = self::reedSolomon($block, $ecPerBlock);
            }
        }

        // Interleave: column-wise across blocks, data first then EC.
        $result = [];

        $longest = 0;
        foreach ($dataBlocks as $block) {
            $longest = max($longest, count($block));
        }
        for ($i = 0; $i < $longest; $i++) {
            foreach ($dataBlocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }
        for ($i = 0; $i < $ecPerBlock; $i++) {
            foreach ($ecBlocks as $block) {
                $result[] = $block[$i];
            }
        }

        return $result;
    }

    /**
     * Reed-Solomon error-correction codewords for one block.
     *
     * @param  list<int>  $data
     * @return list<int>
     */
    private static function reedSolomon(array $data, int $ecLength): array
    {
        self::initGaloisTables();

        // Generator polynomial (x - a^0)(x - a^1)…(x - a^(ecLength-1)).
        $generator = [1];
        for ($i = 0; $i < $ecLength; $i++) {
            $next = array_fill(0, count($generator) + 1, 0);
            foreach ($generator as $j => $coeff) {
                $next[$j] ^= $coeff;
                $next[$j + 1] ^= self::gfMul($coeff, self::$gfExp[$i]);
            }
            $generator = $next;
        }

        $remainder = array_merge($data, array_fill(0, $ecLength, 0));

        for ($i = 0, $n = count($data); $i < $n; $i++) {
            $factor = $remainder[$i];
            if ($factor === 0) {
                continue;
            }
            foreach ($generator as $j => $coeff) {
                $remainder[$i + $j] ^= self::gfMul($coeff, $factor);
            }
        }

        return array_values(array_slice($remainder, count($data)));
    }

    private static function initGaloisTables(): void
    {
        if (self::$gfExp !== null) {
            return;
        }

        $exp = array_fill(0, 512, 0);
        $log = array_fill(0, 256, 0);

        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D; // primitive polynomial for QR's GF(256)
            }
        }
        for ($i = 255; $i < 512; $i++) {
            $exp[$i] = $exp[$i - 255];
        }

        self::$gfExp = $exp;
        self::$gfLog = $log;
    }

    private static function gfMul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        return self::$gfExp[(self::$gfLog[$a] + self::$gfLog[$b]) % 255];
    }

    // ── Symbol construction ──────────────────────────────────────────────

    /**
     * @param  list<int>  $codewords
     * @return array<int,array<int,int>>
     */
    private static function buildMatrix(int $version, array $codewords): array
    {
        $size = $version * 4 + 17;

        $matrix = array_fill(0, $size, array_fill(0, $size, 0));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        self::placeFinder($matrix, $reserved, $size, 0, 0);
        self::placeFinder($matrix, $reserved, $size, 0, $size - 7);
        self::placeFinder($matrix, $reserved, $size, $size - 7, 0);

        self::placeAlignment($matrix, $reserved, $version, $size);

        // Timing patterns.
        for ($i = 8; $i < $size - 8; $i++) {
            $bit = $i % 2 === 0 ? 1 : 0;
            $matrix[6][$i] = $bit;
            $reserved[6][$i] = true;
            $matrix[$i][6] = $bit;
            $reserved[$i][6] = true;
        }

        // Format-information areas (values written per-mask later) plus the
        // permanently dark module.
        for ($i = 0; $i <= 8; $i++) {
            if ($i !== 6) {
                $reserved[8][$i] = true;
                $reserved[$i][8] = true;
            }
        }
        for ($i = 0; $i < 8; $i++) {
            $reserved[8][$size - 1 - $i] = true;
            $reserved[$size - 1 - $i][8] = true;
        }
        $matrix[$size - 8][8] = 1;
        $reserved[$size - 8][8] = true;

        if ($version >= 7) {
            self::placeVersionInfo($matrix, $reserved, $version, $size);
        }

        self::placeData($matrix, $reserved, $size, $codewords);

        // Try all eight masks, keep the least-penalised symbol.
        $best = null;
        $bestPenalty = PHP_INT_MAX;

        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = $matrix;
            for ($row = 0; $row < $size; $row++) {
                for ($col = 0; $col < $size; $col++) {
                    if (! $reserved[$row][$col] && self::maskApplies($mask, $row, $col)) {
                        $candidate[$row][$col] ^= 1;
                    }
                }
            }
            self::placeFormatInfo($candidate, $size, $mask);

            $penalty = self::penalty($candidate, $size);
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * @param  array<int,array<int,int>>  $matrix
     * @param  array<int,array<int,bool>>  $reserved
     */
    private static function placeFinder(array &$matrix, array &$reserved, int $size, int $row, int $col): void
    {
        // -1..7 covers the 7×7 eye plus its one-module light separator.
        for ($i = -1; $i <= 7; $i++) {
            for ($j = -1; $j <= 7; $j++) {
                $r = $row + $i;
                $c = $col + $j;
                if ($r < 0 || $r >= $size || $c < 0 || $c >= $size) {
                    continue;
                }
                $onRing = $i >= 0 && $i <= 6 && ($j === 0 || $j === 6);
                $onRing = $onRing || ($j >= 0 && $j <= 6 && ($i === 0 || $i === 6));
                $inCore = $i >= 2 && $i <= 4 && $j >= 2 && $j <= 4;

                $matrix[$r][$c] = ($onRing || $inCore) ? 1 : 0;
                $reserved[$r][$c] = true;
            }
        }
    }

    /**
     * @param  array<int,array<int,int>>  $matrix
     * @param  array<int,array<int,bool>>  $reserved
     */
    private static function placeAlignment(array &$matrix, array &$reserved, int $version, int $size): void
    {
        $centres = self::ALIGNMENT[$version];
        $last = $size - 7;

        foreach ($centres as $row) {
            foreach ($centres as $col) {
                // The three finder corners already own these positions.
                if (($row === 6 && $col === 6)
                    || ($row === 6 && $col === $last)
                    || ($row === $last && $col === 6)) {
                    continue;
                }
                for ($i = -2; $i <= 2; $i++) {
                    for ($j = -2; $j <= 2; $j++) {
                        $dark = max(abs($i), abs($j)) !== 1 ? 1 : 0;
                        $matrix[$row + $i][$col + $j] = $dark;
                        $reserved[$row + $i][$col + $j] = true;
                    }
                }
            }
        }
    }

    /**
     * @param  array<int,array<int,int>>  $matrix
     * @param  array<int,array<int,bool>>  $reserved
     */
    private static function placeVersionInfo(array &$matrix, array &$reserved, int $version, int $size): void
    {
        $remainder = $version << 12;
        for ($i = 17; $i >= 12; $i--) {
            if ($remainder >> $i & 1) {
                $remainder ^= 0x1F25 << ($i - 12);
            }
        }
        $bits = ($version << 12) | $remainder;

        for ($i = 0; $i < 18; $i++) {
            $bit = $bits >> $i & 1;
            $row = intdiv($i, 3);
            $col = $size - 11 + $i % 3;

            $matrix[$row][$col] = $bit;
            $reserved[$row][$col] = true;
            $matrix[$col][$row] = $bit;
            $reserved[$col][$row] = true;
        }
    }

    /**
     * @param  array<int,array<int,int>>  $matrix
     * @param  array<int,array<int,bool>>  $reserved
     * @param  list<int>  $codewords
     */
    private static function placeData(array &$matrix, array &$reserved, int $size, array $codewords): void
    {
        $bits = '';
        foreach ($codewords as $codeword) {
            $bits .= str_pad(decbin($codeword), 8, '0', STR_PAD_LEFT);
        }
        $total = strlen($bits);
        $index = 0;

        $col = $size - 1;
        $upward = true;

        while ($col > 0) {
            if ($col === 6) {
                $col--; // the vertical timing column is not a data column
            }

            for ($i = 0; $i < $size; $i++) {
                $row = $upward ? $size - 1 - $i : $i;

                foreach ([$col, $col - 1] as $c) {
                    if ($reserved[$row][$c]) {
                        continue;
                    }
                    // Past the end of the stream these are the symbol's
                    // remainder bits, which are zero by definition.
                    $matrix[$row][$c] = $index < $total ? (int) $bits[$index] : 0;
                    $index++;
                }
            }

            $upward = ! $upward;
            $col -= 2;
        }
    }

    /** @param array<int,array<int,int>> $matrix */
    private static function placeFormatInfo(array &$matrix, int $size, int $mask): void
    {
        $format = (self::EC_LEVEL_BITS << 3) | $mask;

        $remainder = $format << 10;
        for ($i = 14; $i >= 10; $i--) {
            if ($remainder >> $i & 1) {
                $remainder ^= 0x537 << ($i - 10);
            }
        }
        $bits = (($format << 10) | $remainder) ^ 0x5412;

        for ($i = 0; $i < 15; $i++) {
            $bit = $bits >> $i & 1;

            // Vertical copy — down column 8: beside the top-left finder
            // (row 6 is the timing pattern, hence the +1 step), then up
            // from the bottom-left finder. Row size-8 is the permanently
            // dark module and is deliberately not in this range.
            if ($i < 6) {
                $matrix[$i][8] = $bit;
            } elseif ($i < 8) {
                $matrix[$i + 1][8] = $bit;
            } else {
                $matrix[$size - 15 + $i][8] = $bit;
            }

            // Horizontal copy — along row 8: right-to-left beneath the
            // top-right finder, then continuing beside the top-left one
            // (again stepping over the timing column).
            if ($i < 8) {
                $matrix[8][$size - $i - 1] = $bit;
            } elseif ($i === 8) {
                $matrix[8][7] = $bit;
            } else {
                $matrix[8][14 - $i] = $bit;
            }
        }
    }

    private static function maskApplies(int $mask, int $row, int $col): bool
    {
        return match ($mask) {
            0 => ($row + $col) % 2 === 0,
            1 => $row % 2 === 0,
            2 => $col % 3 === 0,
            3 => ($row + $col) % 3 === 0,
            4 => (intdiv($row, 2) + intdiv($col, 3)) % 2 === 0,
            5 => ($row * $col) % 2 + ($row * $col) % 3 === 0,
            6 => (($row * $col) % 2 + ($row * $col) % 3) % 2 === 0,
            default => (($row + $col) % 2 + ($row * $col) % 3) % 2 === 0,
        };
    }

    /**
     * The four standard mask-evaluation penalties (ISO/IEC 18004 §8.8.2).
     *
     * @param  array<int,array<int,int>>  $matrix
     */
    private static function penalty(array $matrix, int $size): int
    {
        $score = 0;

        // Rule 1 — runs of five or more same-coloured modules.
        for ($i = 0; $i < $size; $i++) {
            foreach ([true, false] as $horizontal) {
                $run = 1;
                $previous = -1;
                for ($j = 0; $j < $size; $j++) {
                    $value = $horizontal ? $matrix[$i][$j] : $matrix[$j][$i];
                    if ($value === $previous) {
                        $run++;
                    } else {
                        if ($run >= 5) {
                            $score += 3 + ($run - 5);
                        }
                        $run = 1;
                        $previous = $value;
                    }
                }
                if ($run >= 5) {
                    $score += 3 + ($run - 5);
                }
            }
        }

        // Rule 2 — 2×2 blocks of one colour.
        for ($row = 0; $row < $size - 1; $row++) {
            for ($col = 0; $col < $size - 1; $col++) {
                $value = $matrix[$row][$col];
                if ($value === $matrix[$row][$col + 1]
                    && $value === $matrix[$row + 1][$col]
                    && $value === $matrix[$row + 1][$col + 1]) {
                    $score += 3;
                }
            }
        }

        // Rule 3 — finder-like 1:1:3:1:1 patterns with four light modules.
        $patterns = ['10111010000', '00001011101'];
        for ($i = 0; $i < $size; $i++) {
            $rowBits = '';
            $colBits = '';
            for ($j = 0; $j < $size; $j++) {
                $rowBits .= $matrix[$i][$j];
                $colBits .= $matrix[$j][$i];
            }
            foreach ($patterns as $pattern) {
                $score += 40 * substr_count($rowBits, $pattern);
                $score += 40 * substr_count($colBits, $pattern);
            }
        }

        // Rule 4 — deviation from a 50 % dark ratio.
        $dark = 0;
        foreach ($matrix as $row) {
            $dark += array_sum($row);
        }
        $percent = $dark * 100 / ($size * $size);
        $score += (int) (floor(abs($percent - 50) / 5) * 10);

        return $score;
    }
}
