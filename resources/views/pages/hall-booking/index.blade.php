@extends('layouts.app')

@section('content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8 bg-temple">

    {{-- Breadcrumb --}}
    <nav class="text-sm text-amber-100/30 mb-6">
        <a href="{{ route('home') }}" class="hover:text-gold transition">મુખ્ય પૃષ્ઠ</a>
        <span class="mx-2">/</span>
        <span class="text-gold">હોલ બુકિંગ</span>
    </nav>

    {{-- Hero Section --}}
    <div class="text-center mb-10">
        <h1 class="divine-heading text-3xl sm:text-4xl">હોલ બુકિંગ</h1>
        <p class="mt-3 text-amber-100/50 max-w-2xl mx-auto leading-relaxed">
            શ્રી પાતળિયા હનુમાનજી સેવા ટ્રસ્ટ દ્વારા ભક્તો માટે લગ્ન, સત્સંગ, ધાર્મિક કાર્યક્રમો તથા સામાજિક ઉત્સવો માટે વિશાળ હોલ ઉપલબ્ધ છે.
        </p>
    </div>

    {{-- Image Gallery Slideshow --}}
    <div class="mb-10" x-data="hallGallery()">
        <div class="relative aspect-video rounded-2xl overflow-hidden bg-amber-900/20">
            @if($hall->image_path)
                <img src="{{ asset('storage/' . $hall->image_path) }}" alt="{{ $hall->name }}" class="w-full h-full object-cover">
            @else
                <template x-for="(img, idx) in images" :key="idx">
                    <div x-show="current === idx"
                         x-transition:enter="transition ease-out duration-500"
                         x-transition:enter-start="opacity-0"
                         x-transition:enter-end="opacity-100"
                         x-transition:leave="transition ease-in duration-300"
                         x-transition:leave-start="opacity-100"
                         x-transition:leave-end="opacity-0"
                         class="absolute inset-0 flex items-center justify-center bg-gradient-to-br from-amber-900/30 to-stone-900/60">
                        <div class="text-center">
                            <span class="text-7xl" x-text="img.icon"></span>
                            <p class="mt-3 text-amber-100/40 text-sm" x-text="img.label"></p>
                        </div>
                    </div>
                </template>

                {{-- Prev/Next Buttons --}}
                <button @click="prev()" class="absolute left-3 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full bg-black/40 border border-amber-800/30 flex items-center justify-center text-amber-100/60 hover:text-gold hover:bg-black/60 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <button @click="next()" class="absolute right-3 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full bg-black/40 border border-amber-800/30 flex items-center justify-center text-amber-100/60 hover:text-gold hover:bg-black/60 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </button>

                {{-- Dots --}}
                <div class="absolute bottom-3 left-1/2 -translate-x-1/2 flex gap-2">
                    <template x-for="(img, idx) in images" :key="'dot-' + idx">
                        <button @click="current = idx"
                                :class="current === idx ? 'bg-gold' : 'bg-amber-100/30'"
                                class="w-2 h-2 rounded-full transition"></button>
                    </template>
                </div>
            @endif
        </div>
    </div>

    {{-- Hall Details Card --}}
    <div class="card-sacred p-6 sm:p-8 mb-8">
        <h2 class="divine-heading text-xl sm:text-2xl mb-4">{{ $hall->name }}</h2>

        @if($hall->description)
            <p class="text-amber-100/60 leading-relaxed mb-6">{!! nl2br(e($hall->description)) !!}</p>
        @endif

        {{-- Capacity --}}
        <div class="flex items-center gap-2 mb-5">
            <svg class="w-5 h-5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
            <span class="text-amber-100/70 text-sm">ક્ષમતા: <strong class="text-gold">{{ $hall->capacity }} વ્યક્તિ</strong></span>
        </div>

        {{-- Amenities --}}
        @if($hall->amenities && count($hall->amenities) > 0)
            <div class="mb-5">
                <h3 class="text-sm font-semibold text-amber-500 mb-2">સુવિધાઓ</h3>
                <div class="flex flex-wrap gap-2">
                    @foreach($hall->amenities as $amenity)
                        <span class="inline-block px-3 py-1 text-xs font-medium rounded-full bg-amber-900/30 text-amber-400 border border-amber-800/20">{{ $amenity }}</span>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Rules --}}
        @if($hall->rules)
            <div class="mb-5">
                <h3 class="text-sm font-semibold text-amber-500 mb-2">નિયમો</h3>
                <div class="text-amber-100/50 text-sm leading-relaxed">{!! nl2br(e($hall->rules)) !!}</div>
            </div>
        @endif

        {{-- Pricing Table --}}
        <div>
            <h3 class="text-sm font-semibold text-amber-500 mb-3">ભાડા વિગત</h3>
            <div class="overflow-hidden rounded-lg border border-amber-800/20">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-amber-900/20">
                            <th class="px-4 py-2.5 text-left text-amber-400 font-semibold">પ્રકાર</th>
                            <th class="px-4 py-2.5 text-right text-amber-400 font-semibold">ભાડું</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-t border-amber-900/15">
                            <td class="px-4 py-2.5 text-amber-100/70">આખો દિવસ (Full Day)</td>
                            <td class="px-4 py-2.5 text-right font-bold text-gold">₹{{ number_format((float) $hall->price_per_day) }}</td>
                        </tr>
                        <tr class="border-t border-amber-900/15">
                            <td class="px-4 py-2.5 text-amber-100/70">અડધો દિવસ (Half Day)</td>
                            <td class="px-4 py-2.5 text-right font-bold text-gold">₹{{ number_format((float) $hall->price_per_half_day) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Advertorial Text --}}
    <div class="card-sacred p-6 sm:p-8 mb-8">
        <div class="flex items-start gap-4">
            <div class="flex-shrink-0 w-10 h-10 rounded-full bg-amber-900/30 flex items-center justify-center">
                <svg class="w-5 h-5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <p class="text-amber-100/60 leading-relaxed text-sm">
                શ્રી પાતળિયા હનુમાનજી સેવા ટ્રસ્ટ દ્વારા ભક્તો માટે વિશાળ હોલ ઉપલબ્ધ છે. લગ્ન, સત્સંગ, ધાર્મિક કાર્યક્રમો તથા સામાજિક ઉત્સવો માટે નજીવા ભાડે ઉપલબ્ધ. AC, સાઉન્ડ સિસ્ટમ, પાર્કિંગ સહિત સુવિધાઓ.
            </p>
        </div>
    </div>

    {{-- Booking Form --}}
    <div class="card-sacred p-6 sm:p-8" x-data="hallBooking()">
        <h2 class="divine-heading text-xl sm:text-2xl mb-6">બુકિંગ ફોર્મ</h2>

        {{-- Validation Errors --}}
        @if($errors->any())
            <div class="mb-6 p-4 bg-red-900/20 border border-red-800/30 rounded-lg">
                <ul class="list-disc list-inside text-red-400 text-sm space-y-1">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Date Picker --}}
        <div class="mb-5">
            <label class="block text-sm font-medium text-amber-600 mb-1">તારીખ પસંદ કરો <span class="text-red-400">*</span></label>
            <input type="date"
                :min="minDate"
                :max="maxDate"
                x-model="selectedDate"
                @change="checkAvailability()"
                class="w-full sm:w-auto bg-transparent border-amber-800/30 rounded-lg text-amber-100 focus:border-amber-600 focus:ring-amber-600/20">
        </div>

        {{-- Booking Type --}}
        <div class="mb-5">
            <label class="block text-sm font-medium text-amber-600 mb-2">બુકિંગ પ્રકાર <span class="text-red-400">*</span></label>
            <div class="flex flex-wrap gap-3">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="radio" name="booking_type_select" value="full_day" x-model="bookingType" @change="checkAvailability()"
                           class="text-amber-600 border-amber-800/30 focus:ring-amber-600/20 bg-transparent">
                    <span class="text-sm text-amber-100/70">આખો દિવસ (Full Day)</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="radio" name="booking_type_select" value="half_day_morning" x-model="bookingType" @change="checkAvailability()"
                           class="text-amber-600 border-amber-800/30 focus:ring-amber-600/20 bg-transparent">
                    <span class="text-sm text-amber-100/70">અડધો દિવસ - સવાર</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="radio" name="booking_type_select" value="half_day_evening" x-model="bookingType" @change="checkAvailability()"
                           class="text-amber-600 border-amber-800/30 focus:ring-amber-600/20 bg-transparent">
                    <span class="text-sm text-amber-100/70">અડધો દિવસ - સાંજ</span>
                </label>
            </div>
        </div>

        {{-- Availability Status --}}
        <div x-show="selectedDate && bookingType" x-transition class="mb-5">
            <div x-show="checking" class="text-amber-100/40 text-sm py-2">
                <svg class="animate-spin h-5 w-5 inline mr-2" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                </svg>
                ઉપલબ્ધતા ચકાસી રહ્યા છીએ...
            </div>
            <div x-show="!checking && available === true" class="flex items-center gap-2 py-2 px-4 bg-emerald-900/20 border border-emerald-800/30 rounded-lg">
                <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                <span class="text-emerald-400 text-sm font-semibold">ઉપલબ્ધ છે</span>
            </div>
            <div x-show="!checking && available === false" class="flex items-center gap-2 py-2 px-4 bg-red-900/20 border border-red-800/30 rounded-lg">
                <svg class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                <span class="text-red-400 text-sm font-semibold">આ તારીખ બુક થઈ ગયેલ છે</span>
            </div>
        </div>

        {{-- Separator --}}
        <div class="border-t border-amber-900/20 my-6"></div>

        {{-- Contact Details Form --}}
        <form method="POST" action="{{ route('hall.booking.book') }}" x-ref="bookingForm">
            @csrf
            <input type="hidden" name="hall_id" value="{{ $hall->id }}">
            <input type="hidden" name="booking_date" :value="selectedDate">
            <input type="hidden" name="booking_type" :value="bookingType">

            <div class="space-y-4">
                {{-- Contact Name --}}
                <div>
                    <label class="block text-sm font-medium text-amber-600 mb-1">સંપર્ક નામ <span class="text-red-400">*</span></label>
                    <input type="text" name="contact_name" value="{{ old('contact_name', auth('devotee')->user()?->name) }}" required
                        placeholder="તમારું પૂરું નામ"
                        class="w-full bg-transparent border-amber-800/30 rounded-lg text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-amber-600/20">
                </div>

                {{-- Phone --}}
                <div>
                    <label class="block text-sm font-medium text-amber-600 mb-1">ફોન નંબર <span class="text-red-400">*</span></label>
                    <input type="tel" name="contact_phone" value="{{ old('contact_phone', auth('devotee')->user()?->phone) }}" required
                        placeholder="મોબાઇલ નંબર"
                        class="w-full bg-transparent border-amber-800/30 rounded-lg text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-amber-600/20">
                </div>

                {{-- Email --}}
                <div>
                    <label class="block text-sm font-medium text-amber-600 mb-1">ઇમેઇલ <span class="text-amber-100/30 text-xs">(વૈકલ્પિક)</span></label>
                    <input type="email" name="contact_email" value="{{ old('contact_email', auth('devotee')->user()?->email) }}"
                        placeholder="ઇમેઇલ એડ્રેસ"
                        class="w-full bg-transparent border-amber-800/30 rounded-lg text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-amber-600/20">
                </div>

                {{-- Aadhaar Number --}}
                <div>
                    <label class="block text-sm font-medium text-amber-600 mb-1">આધાર નંબર <span class="text-red-400">*</span></label>
                    <input type="text" name="aadhaar_number" value="{{ old('aadhaar_number') }}" required
                        inputmode="numeric" pattern="[0-9]{12}" maxlength="12"
                        placeholder="12 અંકનો આધાર નંબર"
                        class="w-full bg-transparent border-amber-800/30 rounded-lg text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-amber-600/20">
                </div>

                {{-- Full Address --}}
                <div>
                    <label class="block text-sm font-medium text-amber-600 mb-1">સંપૂર્ણ સરનામું <span class="text-red-400">*</span></label>
                    <textarea name="contact_address" rows="3" required
                        placeholder="તમારું સંપૂર્ણ સરનામું"
                        class="w-full bg-transparent border-amber-800/30 rounded-lg text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-amber-600/20">{{ old('contact_address') }}</textarea>
                </div>

                {{-- Purpose --}}
                <div>
                    <label class="block text-sm font-medium text-amber-600 mb-1">હેતુ <span class="text-red-400">*</span></label>
                    <input type="text" name="purpose" value="{{ old('purpose') }}" required
                        placeholder="દા.ત. લગ્ન, સત્સંગ"
                        class="w-full bg-transparent border-amber-800/30 rounded-lg text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-amber-600/20">
                </div>

                {{-- Expected Guests --}}
                <div>
                    <label class="block text-sm font-medium text-amber-600 mb-1">અંદાજિત મહેમાન <span class="text-amber-100/30 text-xs">(વૈકલ્પિક)</span></label>
                    <input type="number" name="expected_guests" value="{{ old('expected_guests') }}" min="1"
                        inputmode="numeric"
                        placeholder="અંદાજિત સંખ્યા"
                        class="w-full bg-transparent border-amber-800/30 rounded-lg text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-amber-600/20">
                </div>
            </div>

            {{-- Price Display --}}
            <div class="mt-6 p-4 bg-amber-900/20 border border-amber-800/30 rounded-lg" x-show="bookingType">
                <div class="flex justify-between items-center">
                    <span class="text-amber-100/50 text-sm">ચૂકવવાની રકમ</span>
                    <span class="text-2xl font-bold text-gold" x-text="'₹' + calculatedAmount.toLocaleString('en-IN')"></span>
                </div>
            </div>

            {{-- Submit Button --}}
            <div class="mt-6">
                @auth('devotee')
                    <button type="submit"
                        :disabled="!selectedDate || !bookingType || available !== true"
                        class="w-full sm:w-auto px-8 py-3 btn-divine disabled:opacity-40 disabled:cursor-not-allowed">
                        <span x-text="bookingType ? 'બુક કરો — ₹' + calculatedAmount.toLocaleString('en-IN') : 'બુક કરો'"></span>
                    </button>
                @else
                    <a href="{{ route('login') }}" class="inline-flex items-center px-8 py-3 btn-divine">
                        બુક કરવા લૉગિન કરો
                    </a>
                @endauth
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
function hallGallery() {
    return {
        current: 0,
        images: [
            { icon: '🏛️', label: 'મંદિર હોલ - મુખ્ય દૃશ્ય' },
            { icon: '🎊', label: 'કાર્યક્રમ હોલ - આંતરિક દૃશ્ય' },
            { icon: '🪔', label: 'સેવા હોલ - સ્ટેજ દૃશ્ય' },
        ],
        prev() {
            this.current = this.current === 0 ? this.images.length - 1 : this.current - 1;
        },
        next() {
            this.current = this.current === this.images.length - 1 ? 0 : this.current + 1;
        },
        init() {
            setInterval(() => this.next(), 5000);
        }
    };
}

function hallBooking() {
    const today = new Date();
    const maxDay = new Date();
    maxDay.setDate(today.getDate() + 90);

    const pricePerDay = {{ (float) $hall->price_per_day }};
    const pricePerHalfDay = {{ (float) $hall->price_per_half_day }};
    const hallId = {{ $hall->id }};

    return {
        selectedDate: '',
        bookingType: '',
        checking: false,
        available: null,
        minDate: today.toISOString().split('T')[0],
        maxDate: maxDay.toISOString().split('T')[0],

        get calculatedAmount() {
            if (this.bookingType === 'full_day') return pricePerDay;
            if (this.bookingType === 'half_day_morning' || this.bookingType === 'half_day_evening') return pricePerHalfDay;
            return 0;
        },

        async checkAvailability() {
            if (!this.selectedDate || !this.bookingType) {
                this.available = null;
                return;
            }
            this.checking = true;
            this.available = null;
            try {
                const res = await fetch(`/hall-booking/check?hall_id=${hallId}&date=${this.selectedDate}&type=${this.bookingType}`);
                const json = await res.json();
                this.available = json.available ?? false;
            } catch (e) {
                this.available = null;
            }
            this.checking = false;
        }
    };
}
</script>
@endpush
@endsection
