{{-- Sticky social icons (YouTube / Instagram / Facebook).
     Admin-managed via Admin → Home Page Settings → Social Links, stored as
     site_social_* SystemSetting rows. Reads the same 300s cached "site_%"
     settings bag as the ribbon / popup / app-install banner, so it costs
     nothing extra per request and the admin save busts the cache.

     Placement: LEFT edge (user request 2026-08-04) — which also fully avoids
     the lg-only darshan widget docked at right-0. Desktop (≥1024px): left
     edge, vertically centered, z-30. Mobile (<1024px): smaller icons stacked
     bottom-left above the safe area; the app-install bottom sheet (z-[90])
     intentionally covers the stack while shown (it is dismissible).
     Colors follow the parchment theme: #FBF5EA buttons, #7A1E1E icons,
     #E8751A on hover. --}}
@php
    $display = \Illuminate\Support\Facades\Cache::remember('site.display.v1', 300, function () {
        return \App\Models\SystemSetting::query()
            ->where('key', 'like', 'site_%')
            ->pluck('value', 'key')
            ->all();
    });

    $socialLinks = array_filter([
        'YouTube' => trim($display['site_social_youtube'] ?? ''),
        'Instagram' => trim($display['site_social_instagram'] ?? ''),
        'Facebook' => trim($display['site_social_facebook'] ?? ''),
    ]);
@endphp
@if(count($socialLinks) > 0)
    <style>
        .sph-social{position:fixed;left:.625rem;bottom:calc(5.5rem + env(safe-area-inset-bottom));z-index:30;display:flex;flex-direction:column;gap:.5rem;}
        .sph-social a{display:flex;align-items:center;justify-content:center;width:2.25rem;height:2.25rem;border-radius:9999px;background:#FBF5EA;border:1px solid rgba(122,30,30,.2);box-shadow:0 2px 10px rgba(60,30,10,.18);color:#7A1E1E;transition:color .15s ease,transform .15s ease,box-shadow .15s ease;}
        .sph-social a:hover{color:#E8751A;transform:translateX(2px);box-shadow:0 4px 14px rgba(60,30,10,.25);}
        .sph-social svg{width:1.05rem;height:1.05rem;}
        @media (min-width:1024px){
            .sph-social{top:50%;bottom:auto;transform:translateY(-50%);left:.75rem;gap:.625rem;}
            .sph-social a{width:2.5rem;height:2.5rem;}
            .sph-social svg{width:1.2rem;height:1.2rem;}
        }
    </style>
    <nav class="sph-social" aria-label="Social media">
        @if(isset($socialLinks['YouTube']))
            <a href="{{ $socialLinks['YouTube'] }}" target="_blank" rel="noopener" aria-label="YouTube">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
                </svg>
            </a>
        @endif
        @if(isset($socialLinks['Instagram']))
            <a href="{{ $socialLinks['Instagram'] }}" target="_blank" rel="noopener" aria-label="Instagram">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M12 2.163c3.204 0 3.584.012 4.85.07 1.17.053 1.805.249 2.228.415.562.217.96.477 1.382.896.419.42.679.819.896 1.381.164.422.36 1.057.413 2.227.058 1.266.07 1.646.07 4.85s-.012 3.584-.07 4.85c-.053 1.17-.249 1.805-.413 2.227-.217.562-.477.96-.896 1.382-.42.419-.82.679-1.382.896-.423.164-1.058.36-2.228.413-1.266.058-1.646.07-4.85.07s-3.584-.012-4.85-.07c-1.17-.053-1.805-.249-2.227-.413-.562-.217-.96-.477-1.382-.896-.419-.42-.679-.82-.896-1.382-.164-.422-.36-1.057-.413-2.227-.058-1.266-.07-1.646-.07-4.85s.012-3.584.07-4.85c.053-1.17.249-1.805.413-2.227.217-.562.477-.96.896-1.382.42-.419.82-.679 1.382-.896.422-.164 1.057-.36 2.227-.413 1.266-.058 1.646-.07 4.85-.07M12 0C8.741 0 8.332.014 7.052.072 5.775.131 4.902.333 4.14.63a5.88 5.88 0 0 0-2.126 1.384A5.88 5.88 0 0 0 .63 4.14C.333 4.902.131 5.775.072 7.052.014 8.332 0 8.741 0 12s.014 3.668.072 4.948c.059 1.277.261 2.15.558 2.912a5.88 5.88 0 0 0 1.384 2.126A5.88 5.88 0 0 0 4.14 23.37c.762.297 1.635.499 2.912.558C8.332 23.986 8.741 24 12 24s3.668-.014 4.948-.072c1.277-.059 2.15-.261 2.912-.558a5.88 5.88 0 0 0 2.126-1.384 5.88 5.88 0 0 0 1.384-2.126c.297-.762.499-1.635.558-2.912.058-1.28.072-1.689.072-4.948s-.014-3.668-.072-4.948c-.059-1.277-.261-2.15-.558-2.912a5.88 5.88 0 0 0-1.384-2.126A5.88 5.88 0 0 0 19.86.63C19.098.333 18.225.131 16.948.072 15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 1 0 0 12.324 6.162 6.162 0 0 0 0-12.324zM12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm6.406-11.845a1.44 1.44 0 1 0 0 2.881 1.44 1.44 0 0 0 0-2.881z"/>
                </svg>
            </a>
        @endif
        @if(isset($socialLinks['Facebook']))
            <a href="{{ $socialLinks['Facebook'] }}" target="_blank" rel="noopener" aria-label="Facebook">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047v-2.66c0-3.026 1.792-4.697 4.533-4.697 1.313 0 2.686.236 2.686.236v2.971H15.83c-1.491 0-1.956.931-1.956 1.886v2.264h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/>
                </svg>
            </a>
        @endif
    </nav>
@endif
