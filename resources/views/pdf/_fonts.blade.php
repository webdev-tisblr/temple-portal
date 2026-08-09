{{--
    Shared @font-face declarations for every generated PDF (80G receipt,
    seva receipt, store invoice, hall booking invoice, packing slip).

    HISTORICAL NOTE: these @font-face rules date from the DomPDF era. All
    five documents now render through App\Support\Pdf\GujaratiPdf (mPDF),
    which does its own font registration and STRIPS the 'Noto Sans
    Gujarati' family out of the HTML before parsing (see GujaratiPdf::
    render) — script runs are switched by autoScriptToLang instead:
    Gujarati → the bundled OTL-compatible Noto Sans Gujarati, Devanagari
    (Hindi receipts, since 2026-08-09) → mPDF's FreeSerif, Latin → DejaVu
    Sans. So this partial is effectively inert under mPDF; it is kept for
    the file:// TTF paths and in case a document ever renders elsewhere.

    Never switch these documents to DomPDF — it has no Indic shaping and
    garbles matras/conjuncts.
--}}
@php
    $fontRegular = base_path('resources/fonts/NotoSansGujarati-Regular.ttf');
    $fontBold = base_path('resources/fonts/NotoSansGujarati-Bold.ttf');
@endphp
@font-face {
    font-family: 'Noto Sans Gujarati';
    src: url("file://{{ $fontRegular }}") format('truetype');
    font-weight: normal;
    font-style: normal;
}
@font-face {
    font-family: 'Noto Sans Gujarati';
    src: url("file://{{ $fontBold }}") format('truetype');
    font-weight: bold;
    font-style: normal;
}
