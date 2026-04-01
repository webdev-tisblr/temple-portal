<!DOCTYPE html>
<html lang="gu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>લૉગિન — શ્રી પાતળિયા હનુમાનજી</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-temple flex items-center justify-center p-4">

<div class="w-full max-w-md" x-data="loginForm()">
    {{-- Header --}}
    <div class="text-center mb-8">
        <img src="{{ asset('images/hanumanji-icon.png') }}" alt="Hanumanji" class="w-20 h-20 rounded-full mx-auto mb-4 border-2 border-amber-600/40 diya-glow" style="box-shadow: 0 0 25px rgba(196,154,42,0.3);">
        <h1 class="text-2xl font-black text-gold tracking-wide">શ્રી પાતળિયા હનુમાનજી</h1>
        <p class="text-amber-200/60 mt-1 text-sm">સેવા ટ્રસ્ટ ડિજિટલ પોર્ટલ</p>
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
            <h2 class="text-xl font-semibold text-amber-100/80 mb-2">લૉગિન / રજિસ્ટર</h2>
            <p class="text-amber-100/40 text-sm mb-6">તમારો મોબાઈલ નંબર દાખલ કરો</p>

            <form action="{{ route('login.otp.send') }}" method="POST" @submit="loading = true">
                @csrf
                <div class="mb-4">
                    <label for="phone" class="block text-sm font-medium text-amber-600 mb-1">મોબાઈલ નંબર</label>
                    <div class="flex">
                        <span class="inline-flex items-center px-3 rounded-l-lg border border-r-0 border-amber-800/30 bg-amber-900/20 text-amber-500 text-sm font-medium">
                            +91
                        </span>
                        <input
                            type="tel"
                            id="phone"
                            name="phone"
                            maxlength="10"
                            pattern="[6-9][0-9]{9}"
                            placeholder="98765 43210"
                            required
                            autofocus
                            class="flex-1 block w-full rounded-r-lg bg-transparent border-amber-800/30 text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-amber-600/20 text-lg tracking-wider"
                            x-model="phone"
                        >
                    </div>
                </div>

                <button
                    type="submit"
                    class="w-full btn-divine py-3 px-4 disabled:opacity-40 disabled:cursor-not-allowed font-semibold"
                    :disabled="phone.length !== 10 || loading"
                >
                    <span x-show="!loading">OTP મોકલો</span>
                    <span x-show="loading" class="flex items-center justify-center">
                        <svg class="animate-spin h-5 w-5 mr-2" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        મોકલી રહ્યા છીએ...
                    </span>
                </button>
            </form>
        </div>

        {{-- Step 2: OTP Verification --}}
        <div x-show="step === 2" x-transition>
            <h2 class="text-xl font-semibold text-amber-100/80 mb-2">OTP દાખલ કરો</h2>
            <p class="text-amber-100/40 text-sm mb-6">
                <span x-text="'+91 ' + phone"></span> પર OTP મોકલવામાં આવ્યો છે
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
                    <span x-show="!loading">ચકાસો</span>
                    <span x-show="loading" class="flex items-center justify-center">
                        <svg class="animate-spin h-5 w-5 mr-2" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        ચકાસી રહ્યા છીએ...
                    </span>
                </button>
            </form>

            <button
                @click="step = 1; otpDigits = ['','','','','','']"
                class="w-full mt-3 text-amber-500 hover:text-gold text-sm font-medium transition"
            >
                ← નંબર બદલો
            </button>
        </div>

    </div>

    <p class="text-center text-amber-100/20 text-xs mt-6">
        &copy; {{ date('Y') }} શ્રી પાતળિયા હનુમાનજી સેવા ટ્રસ્ટ
    </p>
</div>

<script>
function loginForm() {
    return {
        step: {{ session('otp_sent') ? '2' : '1' }},
        phone: '{{ session("phone", "") }}',
        otpDigits: ['', '', '', '', '', ''],
        loading: false,

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
