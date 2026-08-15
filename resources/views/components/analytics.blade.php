{{-- Google Analytics 4.

     Reads an admin-editable System Setting rather than a hardcoded id or an
     .env value, so the trust can change or clear it without a deploy — and
     so a blank setting renders NOTHING at all rather than an empty gtag call
     that reports to nowhere.

     Only ever included from the PUBLIC layout. Filament has its own layout,
     so /admin is never tagged: counting the trust's own clicks alongside
     devotees' would quietly inflate every number on the report.

     This snippet becomes part of the edge-cached HTML for guest pages, so
     changing the id later needs a Cloudflare purge to take effect. --}}
@php
    $gaId = trim((string) \App\Models\SystemSetting::getValue('google_analytics_id', ''));
@endphp

@if ($gaId !== '')
    <script async src="https://www.googletagmanager.com/gtag/js?id={{ $gaId }}"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', @json($gaId));
    </script>
@endif
