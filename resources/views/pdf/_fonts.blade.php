{{--
    Shared @font-face declarations for every DomPDF-rendered PDF
    (80G receipt, store invoice, hall booking invoice, packing slip).

    DomPDF reads the TTF at the `file://` URL on first render and caches
    metadata into storage/fonts/. The TTFs live in the repo so deploys
    don't break.

    Set body font-family to 'Noto Sans Gujarati' first so Gujarati glyphs
    render correctly; DomPDF walks the font-family list per character
    so Latin chars also render fine since Noto Sans Gujarati ships with
    basic Latin glyphs.
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
