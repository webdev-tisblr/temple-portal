<?php

declare(strict_types=1);

namespace App\Support\Pdf;

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;

/**
 * mPDF factory for documents that carry Gujarati text.
 *
 * DomPDF has no Indic text shaping, so Gujarati conjuncts and matras
 * rendered in the wrong visual order. mPDF shapes them correctly via
 * OTL — but only with an OTL-format the parser understands, hence the
 * bundled 2017 Noto Sans Gujarati build (the current Google build uses
 * GPOS lookups mPDF cannot read).
 *
 * Latin text stays on DejaVu Sans; Gujarati script runs are switched
 * automatically (autoScriptToLang → GujaratiLanguageToFont). Because
 * the old Gujarati build has no Latin glyphs, render() strips the
 * 'Noto Sans Gujarati' family from the view CSS so Latin resolves to
 * DejaVu rather than tofu.
 */
final class GujaratiPdf
{
    /**
     * Render a Blade view to PDF bytes.
     *
     * @param array $mpdfConfig extra Mpdf config (format, margins, …)
     */
    public static function render(string $view, array $data, array $mpdfConfig = []): string
    {
        $html = view($view, $data)->render();
        $html = str_ireplace(["'Noto Sans Gujarati',", '"Noto Sans Gujarati",'], '', $html);

        $tempDir = storage_path('app/mpdf');
        if (! is_dir($tempDir)) {
            @mkdir($tempDir, 0755, true);
        }

        $mpdf = new Mpdf(array_merge([
            'tempDir' => $tempDir,
            'fontDir' => array_merge(
                (new ConfigVariables())->getDefaults()['fontDir'],
                [resource_path('fonts')],
            ),
            'fontdata' => array_merge(
                (new FontVariables())->getDefaults()['fontdata'],
                [
                    'notosansgujarati' => [
                        'R' => 'NotoSansGujarati-Mpdf-Regular.ttf',
                        'B' => 'NotoSansGujarati-Mpdf-Bold.ttf',
                        'useOTL' => 0xFF,
                        'useKashida' => 75,
                    ],
                ],
            ),
            'default_font' => 'dejavusans',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'languageToFont' => new GujaratiLanguageToFont(),
        ], $mpdfConfig));

        // mPDF's OTL shaper (Otl.php ~L1965) reads one index past $InputClasses
        // via `$gc <= count(...)`, emitting a benign "Undefined array key N"
        // E_WARNING for every Gujarati contextual-substitution run. The glyphs
        // still shape correctly (the missing key just fails the is_array guard),
        // but in production Laravel promotes that warning to a fatal
        // ErrorException — which 500'd the packing slip. Swallow ONLY that
        // specific mPDF warning; anything else returns false so Laravel's
        // handler runs as usual.
        set_error_handler(
            static function (int $errno, string $errstr, string $errfile): bool {
                return str_contains($errstr, 'Undefined array key')
                    && str_contains($errfile, 'mpdf');
            },
            E_WARNING,
        );

        try {
            $mpdf->WriteHTML($html);

            return $mpdf->Output('', 'S');
        } finally {
            restore_error_handler();
        }
    }
}
