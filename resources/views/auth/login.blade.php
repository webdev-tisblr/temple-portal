{{--
    Devotee login — redesigned 2026-08-09 as the web sibling of the app's
    login handoff ("2b").

    Shape: two columns from `lg` up — today's darshan photograph on the left,
    the OTP flow on the cream sheet at the right — collapsing to a single
    column (photo, then sheet) on phones. Photo and sheet meet at a CLEAN
    edge carried by a gold hairline, with the trust crest as a medallion
    straddling it.

    Revised 2026-08-12: the original build faded the photo into the sheet
    colour with a pair of cream gradients so the halves met with no visible
    seam. It bleached the bottom third of every darshan photograph and read
    as a broken render rather than a transition. Replaced with one
    bottom-weighted scrim (legibility only) plus the gold seam rule. The
    app-download block also collapsed from two per-store QR codes to one
    universal code — see the "Get the app" section below.

    Nothing about the OTP flow changed: the same two POSTs, the same Alpine
    state machine, the same inline validation errors and resend path. Only
    the presentation was rebuilt — and rebuilt on the parchment palette
    directly (not on the legacy `!important` compatibility layer, which is
    being shrunk).

    This page is deliberately its own HTML document rather than an extension
    of layouts/app — a login screen wants no site header, nav or footer.
--}}
@php
    // Which step the SERVER thinks we're on. Alpine takes over on boot; this
    // only decides which half gets x-cloak, so a slow Alpine boot doesn't
    // flash both steps at once — and if Alpine never boots, the correct step
    // is still visible.
    $initialStep = session('otp_sent') ? 2 : 1;

    // Today's darshan, with the bundled temple photograph as the floor. The
    // controller already resolved medium_path (falling back to the original);
    // the onerror below is the last line of defence for a CDN miss.
    $fallbackImage = asset('images/hanumanji-hero.jpg');
    $heroImage = $darshanImageUrl ?: $fallbackImage;
    $darshanCaption = $darshanPhoto?->caption;
    $darshanDate = $darshanPhoto?->captured_on?->translatedFormat('j M Y');

    // The universal link OR the exported QR image is enough to render the
    // block on its own — the two per-store URLs are only the fallback path.
    $hasStoreLinks = $universalStoreUrl !== ''
        || $universalQrImage !== null
        || $androidStoreUrl !== ''
        || $iosStoreUrl !== '';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="google" content="notranslate">
    <meta name="robots" content="noindex, follow">
    <title>{{ __('nav.login') }} — {{ __('common.temple_name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Marcellus&family=Noto+Serif+Gujarati:wght@400;500;600;700&family=Hind+Vadodara:wght@400;500;600;700&family=Noto+Sans+Gujarati:wght@400;500;600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-temple">

<div class="min-h-screen lg:grid lg:grid-cols-12" x-data="loginForm()">

    {{-- ── Darshan panel ───────────────────────────────────────────────
         The left seven columns on desktop; a 40vh band above the sheet on
         phones. On desktop it is sticky and exactly one viewport tall, so
         the caption anchored to the photo's base stays visible even when
         the sheet column is longer than the window. --}}
    <aside class="relative overflow-hidden h-[40vh] min-h-[240px] sm:h-[44vh] lg:sticky lg:top-0 lg:self-start lg:h-screen lg:col-span-7">
        <img
            src="{{ $heroImage }}"
            alt="{{ __('login.darshan_alt') }}"
            class="absolute inset-0 h-full w-full object-cover"
            fetchpriority="high"
            decoding="async"
            @if($heroImage !== $fallbackImage)
                onerror="this.onerror=null;this.src='{{ $fallbackImage }}';"
            @endif
        >

        {{-- ONE scrim, bottom-weighted, doing a single job: hold the caption
             chip and the date legible over an unpredictable photograph.

             Rebuilt 2026-08-12. The previous treatment stacked three
             gradients — a legibility wash plus two cream "dissolves" that
             faded the photo into the sheet colour so the columns met with no
             seam. In practice the dissolve ate the bottom third of every
             darshan photograph into a grey-cream haze and read as a
             rendering fault rather than a design. A photograph is either in
             the composition or it is not; this one is, so it gets a clean
             hard edge and the gold rule below carries the join. --}}
        <div class="absolute inset-0 pointer-events-none"
             style="background: linear-gradient(180deg, rgba(42,24,16,0.58) 0%, rgba(42,24,16,0.08) 32%, rgba(42,24,16,0.12) 55%, rgba(42,24,16,0.68) 100%);"></div>

        {{-- The seam itself: a gold hairline along the edge the sheet meets
             — bottom on mobile, right on desktop. Deliberate, not dissolved. --}}
        <div class="absolute inset-x-0 bottom-0 h-px pointer-events-none lg:hidden"
             style="background: linear-gradient(90deg, rgba(200,148,52,0) 0%, rgba(200,148,52,0.75) 22%, rgba(200,148,52,0.75) 78%, rgba(200,148,52,0) 100%);"></div>
        <div class="absolute inset-y-0 right-0 w-px pointer-events-none hidden lg:block"
             style="background: linear-gradient(180deg, rgba(200,148,52,0) 0%, rgba(200,148,52,0.7) 18%, rgba(200,148,52,0.7) 82%, rgba(200,148,52,0) 100%);"></div>

        {{-- Caption anchors bottom-left on every breakpoint now that the
             scrim is dark at the base throughout — it used to jump to the
             top on mobile only because the old cream dissolve bleached the
             bottom. On mobile it clears the crest, which overlaps the seam
             at centre. Only when there IS a darshan photo — labelling the
             bundled fallback photograph "today's darshan" would be a small
             lie. --}}
        @if($darshanPhoto)
        <div class="absolute bottom-0 left-0 max-w-[70%] p-5 pb-7 sm:p-7 lg:max-w-none lg:p-10 xl:p-12">
            <span class="inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-[11px] font-semibold"
                  style="background: rgba(42,24,16,0.42); color: #FFFCF5; border: 1px solid rgba(255,252,245,0.22); backdrop-filter: blur(4px);">
                <span class="h-1.5 w-1.5 rounded-full" style="background: #E8751A;"></span>
                {{ __('login.darshan_today') }}
                @if($darshanDate)
                    <span style="opacity: 0.72;">· {{ $darshanDate }}</span>
                @endif
            </span>

            @if($darshanCaption)
                <p class="mt-3 hidden max-w-md text-base leading-relaxed lg:block"
                   style="color: #FFFCF5; text-shadow: 0 1px 14px rgba(42,24,16,0.65);">
                    {{ $darshanCaption }}
                </p>
            @endif
        </div>
        @endif
    </aside>

    {{-- ── The sheet: identity, OTP flow, app download ─────────────── --}}
    <main class="relative lg:col-span-5 flex flex-col justify-center px-5 pb-10 sm:px-8 lg:px-10 lg:py-8 xl:px-14">
        <div class="mx-auto w-full max-w-[26rem] -mt-12 lg:mt-0">

            {{-- Crest on the photo/sheet seam. Also the way home. --}}
            <div class="text-center">
                <a href="{{ route('home') }}" title="{{ __('login.back_home') }}" class="group inline-block">
                    <span class="inline-flex h-20 w-20 items-center justify-center overflow-hidden rounded-full transition-transform duration-300 group-hover:scale-105 sm:h-[5.25rem] sm:w-[5.25rem]"
                          style="background: #FFFCF5; border: 2px solid rgba(196,95,18,0.30); box-shadow: 0 10px 26px -10px rgba(60,35,10,0.6);">
                        <img src="{{ asset('images/shree-pataliya-hanumanji-logo.png') }}"
                             alt="{{ __('common.temple_name') }}"
                             class="h-full w-full object-cover">
                    </span>
                </a>

                <p class="mt-4 text-[13px] font-semibold" style="color: #C45F12;">{{ __('home.hero_jai') }}</p>
                <h1 class="font-marcellus mt-1 text-[26px] leading-tight sm:text-[28px]" style="color: #2A1810;">
                    {{ __('common.temple_name') }}
                </h1>
                <p class="mt-1.5 text-xs" style="color: #8A7860;">{{ __('common.trust_full') }}</p>
                <p class="mt-3 text-sm" style="color: #5E4F3D;">{{ __('login.tagline') }}</p>
            </div>

            {{-- Flash + validation. Unchanged behaviour — both OTP POSTs
                 return here with errors rendered inline. --}}
            @if(session('success'))
                <div class="mt-6 rounded-xl px-4 py-3 text-sm"
                     style="background: rgba(63,122,63,0.10); border: 1px solid rgba(63,122,63,0.35); color: #2D5F2D;">
                    {{ session('success') }}
                </div>
            @endif

            @if($errors->any())
                <div class="mt-6 rounded-xl px-4 py-3 text-sm"
                     style="background: rgba(168,50,50,0.08); border: 1px solid rgba(168,50,50,0.35); color: #A83232;">
                    @foreach($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            {{-- A login form is a container, not a clickable card, so this
                 uses .dash-panel — .card-sacred with its hover lift removed. --}}
            <div class="dash-panel mt-5 p-5 sm:p-6">

                {{-- Step 1: Phone Number --}}
                <div x-show="step === 1" x-transition @if($initialStep !== 1) x-cloak @endif>
                    <h2 class="font-marcellus text-lg" style="color: #7A1E1E;">{{ __('login.login_register') }}</h2>
                    <p class="mt-1 text-sm" style="color: #5E4F3D;">{{ __('login.enter_mobile') }}</p>

                    <form action="{{ route('login.otp.send') }}" method="POST" class="mt-5" @submit="loading = true">
                        @csrf
                        <div>
                            <label for="phone" class="mb-1.5 block text-xs font-bold" style="color: #5E4F3D;">{{ __('login.mobile_number') }}</label>
                            <div class="flex">
                                <select
                                    x-model="dial"
                                    aria-label="Country code"
                                    class="flex-none rounded-l-xl border border-r-0 pr-7 text-sm font-bold"
                                    style="border-color: rgba(122,30,30,0.20); color: #C45F12;"
                                >
                                    @foreach(config('dial_codes') as $dc)
                                        {{-- Code only — the country name made the closed
                                             select eat half the input row. title keeps
                                             the name on hover for disambiguation. --}}
                                        <option value="{{ $dc['code'] }}" title="{{ $dc['label'] }}">+{{ $dc['code'] }}</option>
                                    @endforeach
                                </select>
                                <input
                                    type="tel"
                                    id="phone"
                                    maxlength="14"
                                    inputmode="numeric"
                                    autocomplete="tel-national"
                                    placeholder="98765 43210"
                                    required
                                    autofocus
                                    {{-- min-w-0, not w-full: a flex child at 100% width plus the
                                         dial-code select overflows the card on a 390px screen
                                         (the old markup did exactly that). --}}
                                    class="min-w-0 flex-1 rounded-r-xl py-3.5 text-lg font-semibold tracking-wide"
                                    style="border-color: rgba(122,30,30,0.20);"
                                    x-model="phone"
                                    @input="phone = phone.replace(/\D/g, '')"
                                >
                                {{-- Canonical value posted to the server: bare national
                                     number for India, cc+number digits otherwise. --}}
                                <input type="hidden" name="phone" :value="fullPhone()">
                            </div>
                        </div>

                        <x-turnstile />

                        <button
                            type="submit"
                            class="btn-divine mt-5 w-full px-4 py-3.5 disabled:cursor-not-allowed disabled:opacity-40"
                            :disabled="!phoneValid() || loading"
                        >
                            <span x-show="!loading">{{ __('login.send_otp') }}</span>
                            <span x-show="loading" x-cloak class="flex items-center justify-center">
                                <svg class="mr-2 h-5 w-5 animate-spin" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                </svg>
                                {{ __('login.sending') }}
                            </span>
                        </button>
                    </form>
                </div>

                {{-- Step 2: OTP Verification --}}
                <div x-show="step === 2" x-transition @if($initialStep !== 2) x-cloak @endif>
                    <h2 class="font-marcellus text-lg" style="color: #7A1E1E;">{{ __('login.enter_otp') }}</h2>
                    <p class="mt-1 text-sm" style="color: #5E4F3D;">
                        <span class="font-semibold" x-text="displayPhone()"></span> {{ __('login.otp_sent_suffix') }}
                    </p>

                    <form action="{{ route('login.otp.verify') }}" method="POST" class="mt-5" @submit="loading = true">
                        @csrf
                        <input type="hidden" name="phone" :value="phone">
                        <input type="hidden" name="code" :value="otpDigits.join('')">

                        <div class="flex justify-center gap-2">
                            @for($i = 0; $i < 6; $i++)
                                <input
                                    type="text"
                                    maxlength="1"
                                    inputmode="numeric"
                                    pattern="[0-9]"
                                    aria-label="{{ __('login.enter_otp') }} {{ $i + 1 }}"
                                    @if($i === 0) autocomplete="one-time-code" @endif
                                    class="h-14 w-11 rounded-xl text-center text-2xl font-bold sm:w-12"
                                    style="border-color: rgba(122,30,30,0.20);"
                                    x-ref="otp{{ $i }}"
                                    x-model="otpDigits[{{ $i }}]"
                                    @input="handleOtpInput({{ $i }}, $event)"
                                    @keydown.backspace="handleOtpBackspace({{ $i }}, $event)"
                                    @paste.prevent="handleOtpPaste($event)"
                                >
                            @endfor
                        </div>

                        <button
                            type="submit"
                            class="btn-divine mt-6 w-full px-4 py-3.5 disabled:cursor-not-allowed disabled:opacity-40"
                            :disabled="otpDigits.join('').length !== 6 || loading"
                        >
                            <span x-show="!loading">{{ __('login.verify') }}</span>
                            <span x-show="loading" x-cloak class="flex items-center justify-center">
                                <svg class="mr-2 h-5 w-5 animate-spin" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                </svg>
                                {{ __('login.verifying') }}
                            </span>
                        </button>
                    </form>

                    <button
                        @click="step = 1; otpDigits = ['','','','','','']"
                        class="mt-3 w-full py-2 text-sm font-semibold transition"
                        style="color: #C45F12;"
                    >
                        {{ __('login.change_number') }}
                    </button>
                </div>

            </div>

            {{-- ── Get the app ──────────────────────────────────────────
                 ONE code when a universal link is configured (2026-08-12):
                 the link itself decides App Store vs Play from the scanning
                 device's user agent, so the visitor never has to work out
                 which of two codes belongs to their phone — which is the
                 whole failure mode of a two-QR block on a wall or a screen.

                 Set `app_universal_store_url` in System Settings → Mobile
                 App to switch to it. With it blank the page falls back to
                 the per-store pair, so nothing breaks before the smart link
                 exists.

                 Codes are generated inline server-side (App\Support\QrCode)
                 — no third-party QR service, no external request, no static
                 image to go stale when the URL changes. Phones get the link
                 as a button instead: a QR is useless on the very device you
                 would scan it with. --}}
            @if($hasStoreLinks)
                <section class="mt-7">
                    <div class="flex items-center gap-3">
                        <span class="h-px flex-1" style="background: rgba(122,30,30,0.14);"></span>
                        <span class="text-[11px] font-bold" style="color: #8A7860;">{{ __('login.app_heading') }}</span>
                        <span class="h-px flex-1" style="background: rgba(122,30,30,0.14);"></span>
                    </div>

                    @if($universalQr || $universalQrImage)
                        {{-- Single code: bigger than either of the old pair,
                             because it no longer shares the row.

                             Rendered from the setting when there is one, else
                             from the exported PNG. The PNG carries no URL we
                             can read, so it is shown as a plain figure rather
                             than a link — there is nothing to link it to. --}}
                        <div class="mt-4 hidden justify-center md:flex">
                            <figure data-qr="app" class="w-[11rem] rounded-2xl p-3.5 text-center"
                                    style="background: #FFFCF5; border: 1px solid rgba(122,30,30,0.12); box-shadow: 0 2px 14px rgba(0,0,0,0.06);">
                                @if($universalQr)
                                    <a href="{{ $universalStoreUrl }}" target="_blank" rel="noopener">{!! $universalQr !!}</a>
                                @else
                                    <img src="{{ $universalQrImage }}"
                                         alt="{{ __('login.app_scan_any') }}"
                                         width="648" height="648"
                                         class="h-auto w-full"
                                         loading="lazy" decoding="async">
                                @endif
                                <figcaption class="mt-2.5 text-[11px] font-semibold" style="color: #5E4F3D;">
                                    {{ __('login.app_scan_any') }}
                                </figcaption>
                            </figure>
                        </div>
                        <p class="mt-3 hidden text-center text-[11px] md:block" style="color: #8A7860;">
                            {{ __('login.app_scan_hint') }}
                        </p>

                        {{-- Phones can't scan a code on their own screen, so
                             they get a tappable link. The universal setting
                             gives one directly; with only the PNG we fall
                             back to the per-store buttons, which DO carry
                             URLs — otherwise a phone visitor would be shown
                             a code they cannot use and no way to install. --}}
                        <div class="mt-4 md:hidden">
                            @if($universalStoreUrl !== '')
                                <a href="{{ $universalStoreUrl }}" target="_blank" rel="noopener" class="btn-temple w-full py-3">
                                    {{ __('login.app_get') }}
                                </a>
                            @else
                                <div class="flex flex-col gap-2">
                                    @if($androidStoreUrl !== '')
                                        <a href="{{ $androidStoreUrl }}" target="_blank" rel="noopener" class="btn-temple w-full py-3">
                                            {{ __('login.app_android') }}
                                        </a>
                                    @endif
                                    @if($iosStoreUrl !== '')
                                        <a href="{{ $iosStoreUrl }}" target="_blank" rel="noopener" class="btn-temple w-full py-3">
                                            {{ __('login.app_ios') }}
                                        </a>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @else
                        @if($androidQr || $iosQr)
                            <div class="mt-4 hidden justify-center gap-4 md:flex">
                                @if($androidQr)
                                    <figure data-qr="app" class="w-[8.5rem] rounded-2xl p-3 text-center"
                                            style="background: #FFFCF5; border: 1px solid rgba(122,30,30,0.12); box-shadow: 0 2px 12px rgba(0,0,0,0.05);">
                                        <a href="{{ $androidStoreUrl }}" target="_blank" rel="noopener">{!! $androidQr !!}</a>
                                        <figcaption class="mt-2 text-[11px] font-semibold" style="color: #5E4F3D;">{{ __('login.app_android') }}</figcaption>
                                    </figure>
                                @endif
                                @if($iosQr)
                                    <figure data-qr="app" class="w-[8.5rem] rounded-2xl p-3 text-center"
                                            style="background: #FFFCF5; border: 1px solid rgba(122,30,30,0.12); box-shadow: 0 2px 12px rgba(0,0,0,0.05);">
                                        <a href="{{ $iosStoreUrl }}" target="_blank" rel="noopener">{!! $iosQr !!}</a>
                                        <figcaption class="mt-2 text-[11px] font-semibold" style="color: #5E4F3D;">{{ __('login.app_ios') }}</figcaption>
                                    </figure>
                                @endif
                            </div>
                            <p class="mt-3 hidden text-center text-[11px] md:block" style="color: #8A7860;">
                                {{ __('login.app_scan_hint') }}
                            </p>
                        @endif

                        <div class="mt-4 flex flex-col gap-2 {{ ($androidQr || $iosQr) ? 'md:hidden' : '' }}">
                            @if($androidStoreUrl !== '')
                                <a href="{{ $androidStoreUrl }}" target="_blank" rel="noopener" class="btn-temple w-full py-3">
                                    {{ __('login.app_android') }}
                                </a>
                            @endif
                            @if($iosStoreUrl !== '')
                                <a href="{{ $iosStoreUrl }}" target="_blank" rel="noopener" class="btn-temple w-full py-3">
                                    {{ __('login.app_ios') }}
                                </a>
                            @endif
                        </div>
                    @endif
                </section>
            @endif

            <div class="mt-7 text-center">
                <a href="{{ route('home') }}"
                   class="inline-flex items-center gap-2 text-sm font-semibold transition hover:opacity-70"
                   style="color: #7A1E1E;">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    {{ __('login.back_home') }}
                </a>
                <p class="mt-4 text-[11px]" style="color: #8A7860;">
                    &copy; {{ date('Y') }} {{ __('common.trust_full') }}
                </p>
            </div>

        </div>
    </main>
</div>

<script>
function loginForm() {
    return {
        step: {{ $initialStep }},
        // After a POST round-trip this is the CANONICAL phone (bare 10
        // digits for India, cc+number digits for international).
        phone: '{{ session("phone", "") }}',
        dial: '91',
        otpDigits: ['', '', '', '', '', ''],
        loading: false,

        fullPhone() {
            return this.dial === '91' ? this.phone : this.dial + this.phone;
        },

        phoneValid() {
            if (this.dial === '91') {
                return /^[6-9]\d{9}$/.test(this.phone);
            }
            const full = this.dial + this.phone;
            return this.phone.length >= 5 && full.length >= 8 && full.length <= 15;
        },

        displayPhone() {
            return this.phone.length === 10 && /^[6-9]/.test(this.phone)
                ? '+91 ' + this.phone
                : '+' + this.phone;
        },

        handleOtpInput(index, event) {
            const value = event.target.value.replace(/\D/g, '');
            this.otpDigits[index] = value.charAt(0) || '';
            event.target.value = this.otpDigits[index];

            if (value && index < 5) {
                this.$refs['otp' + (index + 1)].focus();
            }
        },

        handleOtpBackspace(index, event) {
            if (!this.otpDigits[index] && index > 0) {
                this.$refs['otp' + (index - 1)].focus();
            }
        },

        handleOtpPaste(event) {
            const paste = event.clipboardData.getData('text').replace(/\D/g, '').slice(0, 6);
            for (let i = 0; i < 6; i++) {
                this.otpDigits[i] = paste[i] || '';
            }
            const lastIndex = Math.min(paste.length, 5);
            this.$refs['otp' + lastIndex]?.focus();
        }
    };
}
</script>

</body>
</html>
