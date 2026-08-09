@php
    // Pull everything from SystemSetting so admins can edit footer
    // copy without a code change.
    use App\Models\SystemSetting;
    use App\Models\DarshanTiming;

    // Locale-aware trust identity (gu bare key, hi/en suffixed, gu fallback).
    $trustName    = SystemSetting::getLocalized('trust_name', null, 'શ્રી પાતાળિયા હનુમાનજી સેવા ટ્રસ્ટ');
    $trustTagline = SystemSetting::getLocalized(
        'trust_tagline',
        null,
        'ગુજરાતમાં હનુમાનજીનું પ્રસિદ્ધ ધામ. ભક્તિ, સેવા અને સમર્પણ સાથે મંદિરનું વ્યવસ્થાપન ટ્રસ્ટ દ્વારા થાય છે.'
    );
    $trustAddress = SystemSetting::getLocalized('trust_address', null, __('common.address'));
    $trustPhone   = SystemSetting::getValue('trust_phone');
    $trustEmail   = SystemSetting::getValue('trust_email');
    $trustWhatsApp= SystemSetting::getValue('trust_whatsapp');
    $trust80g     = SystemSetting::getValue('trust_80g_reg_no');
    $mapUrl       = SystemSetting::getValue('trust_map_url');

    // Today's darshan snapshot — cached in the model already so this
    // is cheap to call from every page render.
    $todayTiming  = DarshanTiming::where('is_active', true)
        ->where('day_type', 'regular')
        ->first();
    // Saturday runs on a special schedule — shown alongside the regular one.
    $saturdayTiming = DarshanTiming::where('is_active', true)
        ->where('day_type', 'special')
        ->first();
@endphp

<footer class="border-t border-[rgba(122,30,30,0.12)]" style="background: linear-gradient(180deg, #F4EAD5, #FBF5EA);">

    {{-- Ornamental top --}}
    <div class="flex justify-center -mt-px">
        <div class="w-32 h-px" style="background: linear-gradient(to right, transparent, #c49a2a, transparent);"></div>
    </div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        {{-- 4 columns on desktop, identity column spans 2:
                col 1-2 → trust identity + contact info stacked
                col 3   → Mandir links
                col 4   → Booking & Donation links
                col 5   → Darshan timings (no socials block)
             The contact info now sits directly below the trust
             identity so the brand block reads as one cohesive
             "who/where to find us" unit. --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-10">

            {{-- ── 1. Trust identity + contact info ─────────────────── --}}
            <div class="lg:col-span-2">
                <div class="flex items-center gap-3 mb-4">
                    <img src="{{ asset('images/shree-pataliya-hanumanji-logo.png') }}"
                         alt="{{ $trustName }}"
                         class="w-12 h-12 rounded-full border diya-glow object-cover"
                         style="border-color: rgba(200,148,52,0.45); box-shadow: 0 0 14px rgba(196,154,42,0.25);">
                    <div>
                        <h3 class="text-base font-bold leading-snug" style="color: #7A1E1E;">{{ __('common.temple_name') }}</h3>
                        <p class="text-[10px] font-semibold mt-0.5" style="color: #C45F12;">{{ __('common.trust_subtitle') }}</p>
                    </div>
                </div>
                <p class="text-sm leading-relaxed" style="color: #5E4F3D;">
                    {{ $trustTagline }}
                </p>
                @if($trust80g)
                    <p class="mt-3 text-xs" style="color: #8A7860;">
                        <span class="font-semibold" style="color: #7A1E1E;">80G:</span> {{ $trust80g }}
                    </p>
                @endif

                {{-- Contact info — stacked under the identity block so
                     the "who + where + how to reach" reads as one
                     unit on the left of the footer. --}}
                <ul class="mt-6 space-y-3 text-sm" style="color: #3E3226;">
                    <li class="flex gap-2.5">
                        <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: #C45F12;">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <span>{{ $trustAddress }}</span>
                    </li>
                    @if($trustPhone)
                        <li class="flex gap-2.5">
                            <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: #C45F12;">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                            </svg>
                            <a href="tel:{{ $trustPhone }}" class="hover:underline">{{ $trustPhone }}</a>
                        </li>
                    @endif
                    @if($trustWhatsApp)
                        <li class="flex gap-2.5">
                            <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 24 24" style="color: #25D366;">
                                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884"/>
                            </svg>
                            <a href="https://wa.me/{{ preg_replace('/\D/', '', $trustWhatsApp) }}"
                               target="_blank" rel="noopener"
                               class="hover:underline">WhatsApp</a>
                        </li>
                    @endif
                    @if($trustEmail)
                        <li class="flex gap-2.5">
                            <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: #C45F12;">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                            </svg>
                            <a href="mailto:{{ $trustEmail }}" class="hover:underline">{{ $trustEmail }}</a>
                        </li>
                    @endif
                    @if($mapUrl)
                        <li class="flex gap-2.5">
                            <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: #C45F12;">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m-6 3l6-3"/>
                            </svg>
                            <a href="{{ $mapUrl }}" target="_blank" rel="noopener" class="hover:underline">Google Maps</a>
                        </li>
                    @endif
                </ul>
            </div>

            {{-- ── 2. Mandir — about / history / people / contact ───── --}}
            <div>
                <h3 class="text-xs font-bold mb-4" style="color: #C45F12;">{{ __('nav.mandir') }}</h3>
                <ul class="space-y-2.5 text-sm">
                    {{-- CMS pages listed dynamically (published, top-level,
                         admin order) — mirrors the header's Mandir dropdown. --}}
                    @php $footerLocale = app()->getLocale(); @endphp
                    @foreach(\App\Models\Page::navPages() as $navPage)
                        <li><a href="{{ url('/' . $navPage['slug']) }}" style="color: #3E3226;" class="hover:underline">{{ $navPage["title_{$footerLocale}"] ?: $navPage['title_gu'] }}</a></li>
                    @endforeach
                    <li><a href="{{ route('trustees') }}"  style="color: #3E3226;" class="hover:underline">{{ __('nav.trustees') }}</a></li>
                    <li><a href="{{ route('gallery') }}"   style="color: #3E3226;" class="hover:underline">{{ __('footer.photo_gallery') }}</a></li>
                    <li><a href="{{ route('contact') }}"   style="color: #3E3226;" class="hover:underline">{{ __('nav.contact') }}</a></li>
                </ul>
            </div>

            {{-- ── 3. Booking & donation ────────────────────────────── --}}
            <div>
                <h3 class="text-xs font-bold mb-4" style="color: #C45F12;">{{ __('footer.booking_donation') }}</h3>
                <ul class="space-y-2.5 text-sm">
                    <li><a href="{{ route('donate') }}"        style="color: #3E3226;" class="hover:underline">{{ __('footer.online_donation') }}</a></li>
                    <li><a href="{{ route('seva.index') }}"    style="color: #3E3226;" class="hover:underline">{{ __('footer.seva_booking') }}</a></li>
                    <li><a href="{{ route('halls.index') }}"   style="color: #3E3226;" class="hover:underline">{{ __('nav.halls') }}</a></li>
                    <li><a href="{{ route('projects.index') }}" style="color: #3E3226;" class="hover:underline">{{ __('footer.seva_projects') }}</a></li>
                    <li><a href="{{ route('store.index') }}"   style="color: #3E3226;" class="hover:underline">{{ __('footer.temple_store') }}</a></li>
                </ul>
            </div>

            {{-- ── 4. Darshan + aarti timings ───────────────────────── --}}
            {{-- All four rows render text-only — emoji icons (☀️ 🌙 🪔)
                 were dropped at the trust's request because they
                 rendered inconsistently across Windows/Android. --}}
            <div>
                @if($todayTiming || $saturdayTiming)
                    <h3 class="text-xs font-bold mb-4" style="color: #C45F12;">{{ __('footer.darshan_times') }}</h3>
                    @if($todayTiming)
                        <p class="text-[11px] font-semibold mb-1" style="color:#C45F12;">{{ __('darshan.regular_days') }}</p>
                        <ul class="space-y-2 text-sm" style="color: #3E3226;">
                            @if($todayTiming->morning_open && $todayTiming->morning_close)
                                <li class="flex justify-between gap-3">
                                    <span>{{ __('footer.morning') }}</span>
                                    <span class="font-semibold tabular-nums">{{ \Carbon\Carbon::parse($todayTiming->morning_open)->format('h:i') }} – {{ \Carbon\Carbon::parse($todayTiming->morning_close)->format('h:i A') }}</span>
                                </li>
                            @endif
                            @if($todayTiming->evening_open && $todayTiming->evening_close)
                                <li class="flex justify-between gap-3">
                                    <span>{{ __('footer.evening') }}</span>
                                    <span class="font-semibold tabular-nums">{{ \Carbon\Carbon::parse($todayTiming->evening_open)->format('h:i') }} – {{ \Carbon\Carbon::parse($todayTiming->evening_close)->format('h:i A') }}</span>
                                </li>
                            @endif
                            @if($todayTiming->aarti_morning)
                                <li class="flex justify-between gap-3 pt-1 border-t" style="border-color: rgba(122,30,30,0.10);">
                                    <span>{{ __('footer.morning_aarti') }}</span>
                                    <span class="font-semibold tabular-nums">{{ \Carbon\Carbon::parse($todayTiming->aarti_morning)->format('h:i A') }}</span>
                                </li>
                            @endif
                            @if($todayTiming->aarti_evening)
                                <li class="flex justify-between gap-3">
                                    <span>{{ __('footer.evening_aarti') }}</span>
                                    <span class="font-semibold tabular-nums">{{ \Carbon\Carbon::parse($todayTiming->aarti_evening)->format('h:i A') }}</span>
                                </li>
                            @endif
                        </ul>
                    @endif
                    @if($saturdayTiming)
                        <p class="text-[11px] font-semibold mb-1 mt-3" style="color:#C45F12;">{{ __('darshan.special_saturday') }}</p>
                        <ul class="space-y-2 text-sm" style="color: #3E3226;">
                            @if($saturdayTiming->morning_open && $saturdayTiming->morning_close)
                                <li class="flex justify-between gap-3">
                                    <span>{{ __('footer.morning') }}</span>
                                    <span class="font-semibold tabular-nums">{{ \Carbon\Carbon::parse($saturdayTiming->morning_open)->format('h:i') }} – {{ \Carbon\Carbon::parse($saturdayTiming->morning_close)->format('h:i A') }}</span>
                                </li>
                            @endif
                            @if($saturdayTiming->evening_open && $saturdayTiming->evening_close)
                                <li class="flex justify-between gap-3">
                                    <span>{{ __('footer.evening') }}</span>
                                    <span class="font-semibold tabular-nums">{{ \Carbon\Carbon::parse($saturdayTiming->evening_open)->format('h:i') }} – {{ \Carbon\Carbon::parse($saturdayTiming->evening_close)->format('h:i A') }}</span>
                                </li>
                            @endif
                        </ul>
                    @endif
                @endif
            </div>

        </div>
    </div>

    {{-- ── Legal links ─────────────────────────────────────────── --}}
    <div class="border-t" style="border-color: rgba(122,30,30,0.10);">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3 flex flex-wrap items-center justify-center gap-x-5 gap-y-2 text-xs" style="color: #5E4F3D;">
            <a href="{{ route('legal.privacy') }}" class="hover:underline">{{ __('footer.privacy_policy') }}</a>
            <a href="{{ route('legal.terms') }}" class="hover:underline">{{ __('footer.terms_of_service') }}</a>
            <a href="{{ route('legal.refund') }}" class="hover:underline">{{ __('footer.refund_cancellation') }}</a>
            <a href="{{ route('legal.account-deletion') }}" class="hover:underline">{{ __('footer.delete_account') }}</a>
        </div>
    </div>

    {{-- ── Bottom bar ──────────────────────────────────────────── --}}
    <div class="border-t" style="border-color: rgba(122,30,30,0.10);">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex flex-col sm:flex-row items-center justify-between gap-2 text-xs" style="color: #5E4F3D;">
            {{-- Locale-aware © line: switches with the active language
                 (the SystemSetting trust_name is Gujarati-only). --}}
            <p>&copy; {{ date('Y') }} {{ __('common.trust_full_name') }}</p>
            <p class="text-center sm:text-right">
                {{ __('footer.crafted_tagline') }} —
                A Project by
                <a href="https://theinternetstore.in" target="_blank" rel="noopener"
                   class="font-semibold hover:underline"
                   style="color: #7A1E1E;">The Internet Store</a>
            </p>
        </div>
    </div>
</footer>
