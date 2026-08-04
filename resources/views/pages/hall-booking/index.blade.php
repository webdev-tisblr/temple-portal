@extends('layouts.app')

@section('content')

<x-page-header
    :breadcrumb="[
        ['label' => __('nav.halls'), 'url' => route('halls.index')],
        ['label' => $hall->name],
    ]"
    :title="$hall->name"
    subtitle="{{ __('halls.subtitle_short') }}" />

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 bg-temple">

    {{-- Two-Column Layout — hall details left, booking action in a sticky sidebar. --}}
    <div class="lg:flex lg:gap-8">

        {{-- ========================================== --}}
        {{-- LEFT COLUMN (Content) --}}
        {{-- ========================================== --}}
        <div class="lg:w-2/3 space-y-8">

            {{-- Image Gallery Slideshow --}}
            <div x-data="hallGallery()">
                <div class="relative aspect-[4/3] rounded-2xl overflow-hidden bg-amber-900/20">
                    @if($hall->image_path)
                        <img src="{{ image_url($hall->image_path) }}" alt="{{ $hall->name }}" class="w-full h-full object-cover">
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
            <div class="card-sacred p-6 sm:p-8">
                <h2 class="divine-heading text-xl sm:text-2xl mb-4">{{ $hall->name }}</h2>

                @if($hall->description)
                    {{-- Hall.description is a RichEditor (HTML). Render it as HTML in
                         a prose wrapper; e()+nl2br double-escaped the tags before. --}}
                    <div class="prose prose-invert max-w-none text-amber-100/60 leading-relaxed mb-6">{!! $hall->description !!}</div>
                @endif

                {{-- Capacity --}}
                <div class="flex items-center gap-2 mb-5">
                    <svg class="w-5 h-5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    <span class="text-amber-100/70 text-sm">{{ __('halls.capacity') }} <strong class="text-gold">{{ $hall->capacity }} {{ __('halls.persons') }}</strong></span>
                </div>

                {{-- Amenities --}}
                @if($hall->amenities && count($hall->amenities) > 0)
                    <div class="mb-5">
                        <h3 class="text-sm font-semibold text-amber-500 mb-2">{{ __('halls.facilities') }}</h3>
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
                        <h3 class="text-sm font-semibold text-amber-500 mb-2">{{ __('halls.rules') }}</h3>
                        <div class="text-amber-100/50 text-sm leading-relaxed">{!! nl2br(e($hall->rules)) !!}</div>
                    </div>
                @endif

                @include('partials.media-gallery', ['media' => $hall->media, 'title' => $hall->name, 'heading' => __('halls.gallery')])

                {{-- Pricing Table --}}
                <div>
                    <h3 class="text-sm font-semibold text-amber-500 mb-3">{{ __('halls.rent_details') }}</h3>
                    <div class="overflow-hidden rounded-lg border border-amber-800/20">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="bg-amber-900/20">
                                    <th class="px-4 py-2.5 text-left text-amber-400 font-semibold">{{ __('halls.type') }}</th>
                                    <th class="px-4 py-2.5 text-right text-amber-400 font-semibold">{{ __('halls.rent') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {{-- Full-day only (2026-08-04): single price row. --}}
                                <tr class="border-t border-amber-900/15">
                                    <td class="px-4 py-2.5 text-amber-100/70">{{ __('halls.full_day_paren') }}</td>
                                    <td class="px-4 py-2.5 text-right font-bold text-gold">₹{{ number_format((float) $hall->price_per_day) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Advertorial Text --}}
            <div class="card-sacred p-6 sm:p-8">
                <div class="flex items-start gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-amber-900/30 flex items-center justify-center">
                        <svg class="w-5 h-5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <p class="text-amber-100/60 leading-relaxed text-sm">
                        {{ __('halls.about_text') }}
                    </p>
                </div>
            </div>

        </div>

        {{-- ========================================== --}}
        {{-- RIGHT COLUMN (Sticky Booking Action) --}}
        {{-- ========================================== --}}
        <div class="lg:w-1/3">
            <div class="lg:sticky lg:top-24">

                {{-- Booking Form --}}
                <div class="card-sacred p-6" x-data="hallBooking()">
                    <h2 class="divine-heading text-xl mb-6">{{ __('halls.booking_form') }}</h2>

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

                    {{-- Date picker — Year/Month selects + horizontal chip
                         carousel. Mirrors the seva-detail date picker (and the
                         mobile app). The month's dates come from the
                         /api/v1/halls/{id}/available-dates endpoint; fully
                         booked dates render dimmed/non-clickable. Availability
                         for the chosen booking type is still verified per-chip
                         via checkAvailability() (bulk data can go stale). --}}
                    @php
                        $noDatesThisMonth = match (app()->getLocale()) {
                            'hi' => 'इस महीने कोई तारीख उपलब्ध नहीं है।',
                            'en' => 'No dates available this month.',
                            default => 'આ મહિનામાં કોઈ તારીખ ઉપલબ્ધ નથી.',
                        };
                    @endphp
                    <div class="mb-5">
                        <label class="block text-sm font-medium text-amber-600 mb-2">{{ __('seva.choose_date') }} <span class="text-red-400">*</span></label>

                        <div class="flex gap-2 mb-3">
                            <select x-model.number="selectedYear" @change="onYearChange()"
                                class="w-1/2 bg-transparent border-amber-800/30 rounded-lg text-amber-100 text-sm focus:border-amber-600 focus:ring-amber-600/20">
                                <template x-for="y in years" :key="y">
                                    <option class="bg-stone-900" :value="y" x-text="y" :selected="selectedYear === y"></option>
                                </template>
                            </select>
                            <select x-model.number="selectedMonth" @change="onMonthChange()"
                                class="w-1/2 bg-transparent border-amber-800/30 rounded-lg text-amber-100 text-sm focus:border-amber-600 focus:ring-amber-600/20">
                                <template x-for="m in monthOptions()" :key="m.value">
                                    <option class="bg-stone-900" :value="m.value" x-text="m.label" :selected="selectedMonth === m.value"></option>
                                </template>
                            </select>
                        </div>

                        <div x-show="datesLoading" class="text-amber-100/40 text-xs py-2">
                            {{ __('seva.loading_dates') }}
                        </div>

                        <div x-show="!datesLoading && upcomingDays.length === 0" class="text-sm py-3 px-4 bg-amber-900/10 border border-amber-800/30 rounded-lg text-amber-100/60">
                            {{ $noDatesThisMonth }}
                        </div>

                        <div x-show="!datesLoading && upcomingDays.length > 0"
                             class="flex gap-2 overflow-x-auto pb-2 -mx-1 px-1 snap-x"
                             style="scrollbar-width: thin;">
                            <template x-for="day in upcomingDays" :key="day.date">
                                <button type="button" @click="!day.disabled && pickDate(day.date)"
                                    :disabled="day.disabled"
                                    :class="day.disabled
                                        ? 'bg-amber-900/10 text-amber-100/20 border-amber-900/20 cursor-not-allowed'
                                        : (selectedDate === day.date
                                            ? 'bg-gradient-to-br from-amber-600 to-amber-500 text-stone-900 border-amber-500 shadow-md'
                                            : 'bg-transparent text-amber-100/70 border-amber-800/30 hover:border-amber-600')"
                                    class="flex-shrink-0 w-16 py-2 border rounded-xl text-center transition snap-start">
                                    <span class="block text-[10px] font-medium opacity-80" x-text="day.dayLabel"></span>
                                    <span class="block text-xl font-black leading-none mt-0.5" x-text="day.dayOfMonth"></span>
                                    <span class="block text-[10px] mt-0.5 opacity-70" x-text="day.monthLabel"></span>
                                </button>
                            </template>
                        </div>
                    </div>

                    {{-- Full-day only (2026-08-04): booking-type radios removed.
                         Picking a date immediately shows available / booked. --}}

                    {{-- Availability Status --}}
                    <div x-show="selectedDate" x-transition class="mb-5">
                        <div x-show="checking" class="text-amber-100/40 text-sm py-2">
                            <svg class="animate-spin h-5 w-5 inline mr-2" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                            </svg>
                            {{ __('halls.checking_avail') }}
                        </div>
                        <div x-show="!checking && available === true" class="flex items-center gap-2 py-2 px-4 bg-emerald-900/20 border border-emerald-800/30 rounded-lg">
                            <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            <span class="text-emerald-400 text-sm font-semibold">{{ __('halls.available') }}</span>
                        </div>
                        <div x-show="!checking && available === false" class="flex items-center gap-2 py-2 px-4 bg-red-900/20 border border-red-800/30 rounded-lg">
                            <svg class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            <span class="text-red-400 text-sm font-semibold">{{ __('halls.booked') }}</span>
                        </div>
                    </div>

                    {{-- Separator --}}
                    <div class="border-t border-amber-900/20 my-6"></div>

                    {{-- Contact Details Form --}}
                    <form method="POST" action="{{ route('hall.booking.book') }}" x-ref="bookingForm">
                        @csrf
                        <input type="hidden" name="hall_id" value="{{ $hall->id }}">
                        <input type="hidden" name="booking_date" :value="selectedDate">

                        <div class="space-y-4">
                            {{-- Contact Name --}}
                            <div>
                                <label class="block text-sm font-medium text-amber-600 mb-1">{{ __('halls.contact_name') }} <span class="text-red-400">*</span></label>
                                <input type="text" name="contact_name" value="{{ old('contact_name', auth('devotee')->user()?->name) }}" required
                                    placeholder="{{ __('store.name_placeholder') }}"
                                    class="w-full bg-transparent border-amber-800/30 rounded-lg text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-amber-600/20">
                            </div>

                            {{-- Phone --}}
                            <div>
                                <label class="block text-sm font-medium text-amber-600 mb-1">{{ __('halls.phone_number') }} <span class="text-red-400">*</span></label>
                                <input type="tel" name="contact_phone" value="{{ old('contact_phone', auth('devotee')->user()?->phone) }}" required
                                    placeholder="{{ __('halls.mobile_placeholder') }}"
                                    class="w-full bg-transparent border-amber-800/30 rounded-lg text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-amber-600/20">
                            </div>

                            {{-- Purpose --}}
                            <div>
                                <label class="block text-sm font-medium text-amber-600 mb-1">{{ __('halls.purpose') }} <span class="text-red-400">*</span></label>
                                <input type="text" name="purpose" value="{{ old('purpose') }}" required
                                    placeholder="{{ __('halls.purpose_placeholder') }}"
                                    class="w-full bg-transparent border-amber-800/30 rounded-lg text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-amber-600/20">
                            </div>

                            {{-- Expected Guests --}}
                            <div>
                                <label class="block text-sm font-medium text-amber-600 mb-1">{{ __('halls.expected_guests') }} <span class="text-amber-100/30 text-xs">{{ __('store.optional') }}</span></label>
                                <input type="number" name="expected_guests" value="{{ old('expected_guests') }}" min="1"
                                    inputmode="numeric"
                                    placeholder="{{ __('halls.guests_placeholder') }}"
                                    class="w-full bg-transparent border-amber-800/30 rounded-lg text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-amber-600/20">
                            </div>
                        </div>

                        {{-- Price Display --}}
                        <div class="mt-6 p-4 bg-amber-900/20 border border-amber-800/30 rounded-lg">
                            <div class="flex justify-between items-center">
                                <span class="text-amber-100/50 text-sm">{{ __('halls.amount_to_pay') }}</span>
                                <span class="text-2xl font-bold text-gold" x-text="'₹' + calculatedAmount.toLocaleString('en-IN')"></span>
                            </div>
                        </div>

                        {{-- Submit Button --}}
                        <div class="mt-6">
                            @auth('devotee')
                                <button type="submit"
                                    :disabled="!selectedDate || available !== true"
                                    class="w-full px-8 py-3 btn-divine disabled:opacity-40 disabled:cursor-not-allowed">
                                    <span x-text="@js(__('halls.book_prefix')) + calculatedAmount.toLocaleString('en-IN')"></span>
                                </button>
                            @else
                                <a href="{{ route('login') }}" class="flex items-center justify-center w-full px-8 py-3 btn-divine">
                                    {{ __('seva.login_to_book') }}
                                </a>
                            @endauth
                        </div>
                    </form>
                </div>

            </div>
        </div>

    </div>
</div>

@push('scripts')
<script>
function hallGallery() {
    return {
        current: 0,
        images: [
            { icon: '🏛️', label: @js(__('halls.gallery1')) },
            { icon: '🎊', label: @js(__('halls.gallery2')) },
            { icon: '🪔', label: @js(__('halls.gallery3')) },
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
    // Full-day only (2026-08-04) — one price, no booking-type state.
    const pricePerDay = {{ (float) $hall->price_per_day }};
    const hallId = {{ $hall->id }};

    // Chip labels — local-time midnight so day-of-week / month labels
    // are temple-local.
    const dayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const monthLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const pad = n => String(n).padStart(2, '0');

    // Year/Month picker horizon: current month … same month +5 years,
    // mirroring the available-dates API's accepted range.
    const _now = new Date();
    const currentYear = _now.getFullYear();
    const currentMonthNum = _now.getMonth() + 1;
    const pageLang = document.documentElement.lang || 'en';
    const localizedMonthName = m => new Date(2000, m - 1, 1).toLocaleDateString(pageLang, { month: 'long' });

    return {
        selectedDate: '',
        checking: false,
        available: null,
        upcomingDays: [],
        datesLoading: false,
        selectedYear: currentYear,
        selectedMonth: currentMonthNum,
        years: Array.from({ length: 11 }, (_, i) => currentYear + i),

        monthOptions() {
            const start = this.selectedYear === currentYear ? currentMonthNum : 1;
            const end = this.selectedYear === currentYear + 10 ? currentMonthNum : 12;
            const opts = [];
            for (let m = start; m <= end; m++) {
                opts.push({ value: m, label: localizedMonthName(m) });
            }
            return opts;
        },

        onYearChange() {
            // Clamp the month into the newly valid range (e.g. jumping
            // to the current year mid-year, or the horizon-end year).
            const opts = this.monthOptions();
            if (!opts.some(o => o.value === Number(this.selectedMonth))) {
                this.selectedMonth = opts[0].value;
            }
            this.fetchMonthDates();
        },

        onMonthChange() {
            this.fetchMonthDates();
        },

        init() {
            this.fetchMonthDates();
        },

        async fetchMonthDates() {
            this.datesLoading = true;
            // A month switch invalidates the current selection.
            this.selectedDate = '';
            this.available = null;
            const month = `${this.selectedYear}-${pad(this.selectedMonth)}`;
            try {
                const res = await fetch(`/api/v1/halls/${hallId}/available-dates?month=${month}`);
                const json = await res.json();
                const entries = json.data?.dates || [];
                this.upcomingDays = entries.map(e => {
                    const [y, m, d] = e.date.split('-').map(Number);
                    const dt = new Date(y, m - 1, d);
                    return {
                        date: e.date,
                        dayLabel: dayLabels[dt.getDay()],
                        dayOfMonth: dt.getDate(),
                        monthLabel: monthLabels[dt.getMonth()],
                        // Full-day only: any booking blocks the whole date.
                        disabled: !e.full_day_available,
                    };
                });
            } catch (e) {
                this.upcomingDays = [];
            }
            this.datesLoading = false;
        },

        pickDate(iso) {
            this.selectedDate = iso;
            this.checkAvailability();
        },

        get calculatedAmount() {
            return pricePerDay;
        },

        async checkAvailability() {
            if (!this.selectedDate) {
                this.available = null;
                return;
            }
            this.checking = true;
            this.available = null;
            try {
                const res = await fetch(`/hall-booking/check?hall_id=${hallId}&date=${this.selectedDate}`);
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
