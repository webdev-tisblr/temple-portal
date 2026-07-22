<?php

declare(strict_types=1);

namespace App\Support\Pdf;

use Mpdf\Language\LanguageToFont;

/**
 * mPDF maps Gujarati to FreeSerif, which has no Gujarati glyphs.
 * Route it to our bundled (2017 OTL-compatible) Noto Sans Gujarati
 * instead; everything else falls through to mPDF's defaults.
 */
class GujaratiLanguageToFont extends LanguageToFont
{
    public function getLanguageOptions($llcc, $adobeCJK)
    {
        [$coreSuitable, $unifont] = parent::getLanguageOptions($llcc, $adobeCJK);

        $lang = strtolower(substr($llcc, 0, 2));
        if (in_array($lang, ['gu'], true)) {
            $unifont = 'notosansgujarati';
        }

        return [$coreSuitable, $unifont];
    }
}
