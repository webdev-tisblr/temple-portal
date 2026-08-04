<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="google" content="notranslate">
    <title>{{ __('nav.login') }} — {{ __('common.temple_name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-temple flex items-center justify-center p-4">

<div class="w-full max-w-md" x-data="loginForm()">
    {{-- Header — the logo also links home --}}
    <div class="text-center mb-8">
        <a href="{{ route('home') }}" title="{{ __('login.back_home') }}" class="inline-block group">
            <img src="{{ asset('images/shree-pataliya-hanumanji-logo.png') }}" alt="{{ __('common.temple_name') }}" class="w-20 h-20 rounded-full mx-auto mb-4 border-2 border-amber-600/40 diya-glow transition-transform group-hover:scale-105" style="box-shadow: 0 0 25px rgba(196,154,42,0.3);">
        </a>
        <h1 class="text-2xl font-black text-gold">{{ __('common.temple_name') }}</h1>
        <p class="text-amber-200/60 mt-1 text-sm">{{ __('login.portal_sub') }}</p>
    </div>

    {{-- Flash Messages --}}
    @if(session('success'))
        <div class="bg-emerald-950/30 border border-emerald-800/30 text-emerald-300 px-4 py-3 rounded-lg mb-4 text-sm">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div class="bg-red-950/30 border border-red-800/30 text-red-300 px-4 py-3 rounded-lg mb-4 text-sm">
            @foreach($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <div class="card-sacred p-6 sm:p-8">

        {{-- Step 1: Phone Number --}}
        <div x-show="step === 1" x-transition>
            <h2 class="text-xl font-semibold text-amber-100/80 mb-2">{{ __('login.login_register') }}</h2>
            <p class="text-amber-100/40 text-sm mb-6">{{ __('login.enter_mobile') }}</p>

            <form action="{{ route('login.otp.send') }}" method="POST" @submit="loading = true">
                @csrf
                <div class="mb-4">
                    <label for="phone" class="block text-sm font-medium text-amber-600 mb-1">{{ __('login.mobile_number') }}</label>
                    <div class="flex">
                        <select
                            x-model="dial"
                            aria-label="Country code"
                            class="rounded-l-lg border border-r-0 border-amber-800/30 bg-amber-900/20 text-amber-500 text-sm font-medium focus:border-amber-600 focus:ring-amber-600/20 pr-7"
                        >
                            @foreach(config('dial_codes') as $dc)
                                {{-- Code only — the country name made the closed
                                     select eat half the input row. title keeps
                                     the name on hover for disambiguation. --}}
                                <option value="{{ $dc['code'] }}" title="{{ $dc['label'] }}" class="bg-[#2a1608] text-amber-100">+{{ $dc['code'] }}</option>
                            @endforeach
                        </select>
                        <input
                            type="tel"
                            id="phone"
                            maxlength="14"
                            inputmode="numeric"
                            placeholder="98765 43210"
                            required
                            autofocus
                            class="flex-1 block w-full rounded-r-lg bg-transparent border-amber-800/30 text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-amber-600/20 text-lg tracking-wider"
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
                    class="w-full btn-divine py-3 px-4 disabled:opacity-40 disabled:cursor-not-allowed font-semibold"
                    :disabled="!phoneValid() || loading"
                >
                    <span x-show="!loading">{{ __('login.send_otp') }}</span>
                    <span x-show="loading" class="flex items-center justify-center">
                        <svg class="animate-spin h-5 w-5 mr-2" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        {{ __('login.sending') }}
                    </span>
                </button>
            </form>
        </div>

        {{-- Step 2: OTP Verification --}}
        <div x-show="step === 2" x-transition>
            <h2 class="text-xl font-semibold text-amber-100/80 mb-2">{{ __('login.enter_otp') }}</h2>
            <p class="text-amber-100/40 text-sm mb-6">
                <span x-text="displayPhone()"></span> {{ __('login.otp_sent_suffix') }}
            </p>

            <form action="{{ route('login.otp.verify') }}" method="POST" @submit="loading = true">
                @csrf
                <input type="hidden" name="phone" :value="phone">
                <input type="hidden" name="code" :value="otpDigits.join('')">

                <div class="flex gap-2 justify-center mb-6">
                    @for($i = 0; $i < 6; $i++)
                        <input
                            type="text"
                            maxlength="1"
                            inputmode="numeric"
                            pattern="[0-9]"
                            class="w-12 h-14 text-center text-2xl font-bold bg-transparent border border-amber-800/30 rounded-lg text-amber-100 focus:border-amber-500 focus:ring-1 focus:ring-amber-600/30 focus:bg-amber-900/20 transition"
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
                    class="w-full btn-divine py-3 px-4 disabled:opacity-40 disabled:cursor-not-allowed font-semibold"
                    :disabled="otpDigits.join('').length !== 6 || loading"
                >
                    <span x-show="!loading">{{ __('login.verify') }}</span>
                    <span x-show="loading" class="flex items-center justify-center">
                        <svg class="animate-spin h-5 w-5 mr-2" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        {{ __('login.verifying') }}
                    </span>
                </button>
            </form>

            <button
                @click="step = 1; otpDigits = ['','','','','','']"
                class="w-full mt-3 text-amber-500 hover:text-gold text-sm font-medium transition"
            >
                {{ __('login.change_number') }}
            </button>
        </div>

    </div>

    {{-- Back to home — below the card, centered. The page bg is CREAM
         (#FBF5EA), so the button is deep maroon on cream (site palette);
         hover inverts to a filled maroon pill with cream text. --}}
    <div class="flex justify-center mt-5">
        <a href="{{ route('home') }}"
           class="inline-flex items-center gap-2 px-6 py-2.5 rounded-full border-2 border-[#7A1E1E]/60 text-[#7A1E1E] text-sm font-bold transition-all duration-200 hover:bg-[#7A1E1E] hover:text-[#FBF5EA] hover:border-[#7A1E1E] hover:shadow-lg">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            {{ __('login.back_home') }}
        </a>
    </div>

    <p class="text-center text-amber-100/20 text-xs mt-6">
        &copy; {{ date('Y') }} {{ __('common.trust_full') }}
    </p>
</div>

<script>
function loginForm() {
    return {
        step: {{ session('otp_sent') ? '2' : '1' }},
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
