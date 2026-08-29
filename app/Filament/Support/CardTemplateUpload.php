<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Forms;

/**
 * The background-artwork upload used by every card template in the admin —
 * greeting cards (donation type / campaign / seva), status templates and
 * darshan share cards.
 *
 * Built 2026-08-29 to fix one complaint with two halves:
 *
 *   • "Cropping issue." The admin had no crop tool at all, so artwork whose
 *     proportions did not match what the card renders at came out with the
 *     wrong part of the picture in frame — and the only fix was to go back to
 *     a phone gallery app, crop there, and re-upload. `imageEditor()` puts
 *     crop/rotate/zoom in the form, with the card's real aspect ratio offered
 *     as the default so a correct crop is the easy one to make.
 *
 *   • "Accept the high res image, but compress it after the cropping." The
 *     5 MB cap rejected artwork straight off a designer's machine. The cap is
 *     now 20 MB, and the shrink happens on the way to R2 rather than being
 *     the admin's problem: every admin upload passes through
 *     UploadedImageCompressor (AppServiceProvider::boot), which scales the
 *     long edge to 2000px and re-encodes. Crucially that runs on what the
 *     EDITOR produced, not the original — so the sequence really is
 *     "accept big → crop → compress".
 *
 * Overlay coordinates are stored in the stored image's pixel space, and the
 * editor blade positions them against the same stored image, so the
 * post-upload downscale cannot shift a layout.
 */
final class CardTemplateUpload
{
    /** Long-edge ceiling the compressor applies, quoted in helper text. */
    private const STORED_MAX_EDGE = 2000;

    /** What we would accept if PHP let us. Prod currently allows 12 MB. */
    private const WANTED_MAX_KB = 20480;

    /**
     * @param  string  $name  The column (greeting_card_template[_hi|_en]).
     * @param  string  $directory  R2 folder.
     * @param  list<string|null>  $ratios  Crop ratios offered, e.g. ['9:16'].
     *                                     A null entry means "free crop".
     */
    public static function make(
        string $name,
        string $directory,
        array $ratios,
        ?string $label = null,
        ?string $helper = null,
    ): Forms\Components\FileUpload {
        $field = Forms\Components\FileUpload::make($name)
            ->directory($directory)
            ->image()
            // High-res in, compressed out. See the class doc-comment.
            //
            // Derived from PHP's OWN limit rather than hardcoded: a form that
            // advertises 20 MB on a box configured for 12 fails the upload
            // silently at the PHP layer, with no validation message to explain
            // it. Raise upload_max_filesize / post_max_size (and nginx's
            // client_max_body_size) on the VPS and this lifts with them.
            ->maxSize(self::maxUploadKb())
            ->imageEditor()
            ->imageEditorAspectRatios($ratios)
            // Mode 2 = crop box constrained to the canvas, which is what an
            // admin expects when the output has a fixed shape.
            ->imageEditorMode(2)
            ->helperText($helper ?? self::defaultHelper($ratios));

        if ($label !== null) {
            $field->label($label);
        }

        return $field;
    }

    /**
     * The largest upload this server will really take, in KB — the smaller of
     * upload_max_filesize and post_max_size, never above what we want anyway.
     */
    public static function maxUploadKb(): int
    {
        $limits = array_filter([
            self::iniBytes('upload_max_filesize'),
            self::iniBytes('post_max_size'),
        ]);

        if ($limits === []) {
            return self::WANTED_MAX_KB;
        }

        // A little headroom under post_max_size: the multipart envelope and
        // Livewire's own fields ride in the same POST as the file.
        $kb = (int) floor(min($limits) / 1024 * 0.95);

        return max(1024, min(self::WANTED_MAX_KB, $kb));
    }

    /** php.ini shorthand ("12M", "512K") → bytes. 0/-1/unset → null. */
    private static function iniBytes(string $key): ?int
    {
        $raw = trim((string) ini_get($key));

        if ($raw === '' || $raw === '0' || $raw === '-1') {
            return null;
        }

        $value = (int) $raw;

        return match (strtolower(substr($raw, -1))) {
            'g' => $value * 1024 ** 3,
            'm' => $value * 1024 ** 2,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /** @param list<string|null> $ratios */
    private static function defaultHelper(array $ratios): string
    {
        $shapes = collect($ratios)->filter()->implode(', ');
        $mb = (int) floor(self::maxUploadKb() / 1024);

        return 'Upload the full-resolution original — up to '.$mb.' MB. Use the crop tool on the thumbnail to frame it'
            .($shapes !== '' ? ' ('.$shapes.')' : '')
            .'; it is stored at up to '.self::STORED_MAX_EDGE.'px on the long edge and re-encoded, so a big file costs nothing.';
    }
}
