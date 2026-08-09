@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 bg-temple">

    <x-breadcrumb
        :items="[
            ['label' => __('home.seva_puja'), 'url' => route('seva.index')],
            ['label' => $seva->name],
        ]"
        class="mb-6" />

    {{-- Title (mobile) --}}
    <h1 class="divine-heading text-2xl sm:text-3xl mb-6 lg:hidden">{{ $seva->name }}</h1>

    {{-- Two-Column Layout — content left, booking action in a sticky sidebar. --}}
    <div class="lg:flex lg:gap-8">

        {{-- ========================================== --}}
        {{-- LEFT COLUMN (Content) --}}
        {{-- ========================================== --}}
        <div class="lg:w-2/3 space-y-8">

            {{-- Image --}}
            <div class="card-sacred overflow-hidden">
                <div class="aspect-[4/3] bg-amber-900/20 flex items-center justify-center">
                    @if($seva->image_path)
                        <img src="{{ image_url($seva->image_path) }}" alt="{{ $seva->name }}" class="w-full h-full object-cover">
                    @else
                        <span class="text-8xl">🙏</span>
                    @endif
                </div>
            </div>

            {{-- Details --}}
            <div class="card-sacred p-6 sm:p-8">
                {{-- Category Badge --}}
                <span class="inline-block px-3 py-1 text-xs font-medium rounded-full mb-3 bg-amber-900/30 text-amber-400">
                    {{ \App\Models\SevaCategory::displayName($seva->getRawOriginal('category')) }}
                </span>

                <h1 class="divine-heading text-2xl sm:text-3xl hidden lg:block">{{ $seva->name }}</h1>

                {{-- Price --}}
                <div class="mt-3">
                    @if($seva->is_variable_price)
                        <span class="text-sm text-amber-100/40">{{ __('seva.min_amount') }}</span>
                        <span class="text-2xl font-bold text-gold ml-1">₹{{ number_format((float) $seva->min_price) }}</span>
                    @else
                        <span class="text-2xl font-bold text-gold">₹{{ number_format((float) $seva->price) }}</span>
                    @endif
                </div>

                {{-- Description --}}
                @if($seva->description)
                    <div class="mt-4 text-amber-100/60 leading-relaxed prose prose-invert prose-sm max-w-none">
                        {!! $seva->description !!}
                    </div>
                @endif

                @include('partials.media-gallery', ['media' => $seva->media, 'title' => $seva->name, 'heading' => __('seva.gallery')])
            </div>

        </div>

        {{-- ========================================== --}}
        {{-- RIGHT COLUMN (Sticky Booking Action) --}}
        {{-- ========================================== --}}
        <div class="lg:w-1/3">
            <div class="lg:sticky lg:top-24">

                {{-- Booking Section --}}
                @if($seva->requires_booking)
                    @guest('devotee')
                        {{-- Guests see a login prompt instead of the booking
                             form (consistent site-wide; 2026-08-04). --}}
                        <div class="card-sacred p-6">
                            <h2 class="text-lg font-semibold text-gold mb-4">{{ __('seva.choose_date_time') }}</h2>
                            <p class="text-sm text-amber-100/60 mb-5">{{ __('halls.login_to_view_form') }}</p>
                            <a href="{{ login_url() }}" class="flex items-center justify-center w-full px-8 py-3 btn-divine">
                                {{ __('seva.login_to_book') }}
                            </a>
                        </div>
                    @else
                    <div class="card-sacred p-6" x-data="slotPicker({{ $seva->id }})">
                        <h2 class="text-lg font-semibold text-gold mb-4">{{ __('seva.choose_date_time') }}</h2>

                        {{-- Product Selection — deliberately NOT gated on
                             selectedDate (2026-08-09). A seva that offers a
                             prasad/product choice must show that choice and
                             the date choice side by side, upfront: the two
                             are independent and canBook() validates them
                             independently. Only the SLOT list stays chained
                             to the date, because slots genuinely differ per
                             date. Sevas with no linked products render
                             nothing here and are unaffected. --}}
                        @if($linkedProducts->isNotEmpty())
                            <div class="mb-5">
                                <span class="eyebrow block text-[11px] text-amber-100/40 mb-1">{{ __('seva.step', ['n' => 1]) }}</span>
                                <label class="block text-sm font-medium text-amber-600 mb-3">{{ $seva->getProductSelectionLabel() }}</label>
                                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                                    @foreach($linkedProducts as $lp)
                                        <button type="button"
                                            @click="selectedProductId = {{ $lp->id }}; selectedVariant = ''"
                                            :class="selectedProductId === {{ $lp->id }} ? 'ring-2 ring-amber-500 border-amber-500' : 'border-amber-800/30 hover:border-amber-600'"
                                            class="group border rounded-xl overflow-hidden transition text-left bg-amber-900/10">
                                            <div class="aspect-[4/3] bg-amber-900/20 overflow-hidden">
                                                @if($lp->image_path)
                                                    <img src="{{ image_url($lp->image_path) }}" alt="{{ $lp->name }}" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-110">
                                                @else
                                                    <div class="w-full h-full flex items-center justify-center">
                                                        <svg class="w-10 h-10 text-amber-800/30" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                                    </div>
                                                @endif
                                            </div>
                                            <div class="p-2">
                                                <p class="text-xs text-amber-100/70 font-medium line-clamp-2">{{ $lp->name }}</p>
                                            </div>
                                        </button>
                                    @endforeach
                                </div>

                                {{-- Variant selection (products with variable options) --}}
                                <template x-if="needsVariant()">
                                    <div class="mt-3">
                                        <label class="block text-xs font-medium text-amber-600 mb-2">{{ __('seva.choose_option') }}</label>
                                        <div class="flex flex-wrap gap-2">
                                            <template x-for="v in (currentProduct()?.variants || [])" :key="v.label">
                                                <button type="button" @click="selectedVariant = v.label" :disabled="!v.in_stock"
                                                    :class="selectedVariant === v.label ? 'bg-gradient-to-r from-amber-600 to-amber-500 text-stone-900 border-amber-500 font-bold' : (v.in_stock ? 'bg-transparent text-amber-100/60 border-amber-800/30 hover:border-amber-600' : 'opacity-30 cursor-not-allowed border-amber-900/20')"
                                                    class="px-3 py-2 border rounded-lg text-xs font-medium transition"
                                                    x-text="v.price > 0 ? (v.label + ' — ₹' + Number(v.price).toLocaleString('en-IN')) : v.label">
                                                </button>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        @endif

                        {{-- Date picker — horizontal chip carousel.
                             Since 2026-08-09 (item 4.1) EVERY date of the
                             month renders: unbookable ones are greyed,
                             non-clickable and carry a "Not Available"
                             ribbon (driven by days_detail's reason_code)
                             instead of being silently hidden. --}}
                        @php
                            $noDatesThisMonth = match (app()->getLocale()) {
                                'hi' => 'इस महीने कोई तारीख उपलब्ध नहीं है।',
                                'en' => 'No dates available this month.',
                                default => 'આ મહિનામાં કોઈ તારીખ ઉપલબ્ધ નથી.',
                            };
                        @endphp
                        <div class="mb-4">
                            @if($linkedProducts->isNotEmpty())
                                <span class="eyebrow block text-[11px] text-amber-100/40 mb-1">{{ __('seva.step', ['n' => 2]) }}</span>
                            @endif
                            <div class="flex items-center justify-between mb-2">
                                <label class="block text-sm font-medium text-amber-600">{{ __('seva.choose_date') }}</label>
                                {{-- Item 4.4 — one server request finds the next
                                     open date + slot (the app used to walk up to
                                     12 months client-side). --}}
                                <button type="button" @click="findNextAvailable()" :disabled="findingNext"
                                    class="inline-flex items-center gap-1 text-xs font-semibold text-amber-500 hover:text-gold disabled:opacity-50 transition">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                    <span x-text="findingNext ? @js(__('availability.searching')) : @js(__('availability.next_available'))"></span>
                                </button>
                            </div>
                            <p x-show="nextAvailableNote" x-transition class="text-[11px] text-amber-100/40 mb-2" x-text="nextAvailableNote"></p>

                            {{-- Year / Month selectors — booking horizon is
                                 current month through +5 years (matches the
                                 available-dates API's 422 range). --}}
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

                            <div x-show="!datesLoading && openDateCount() === 0" class="text-sm py-3 px-4 bg-amber-900/10 border border-amber-800/30 rounded-lg text-amber-100/60">
                                {{ $noDatesThisMonth }}
                            </div>

                            <div x-show="!datesLoading && availableDates.length > 0"
                                 class="flex gap-2 overflow-x-auto pb-2 -mx-1 px-1 snap-x"
                                 style="scrollbar-width: thin;">
                                <template x-for="day in availableDates" :key="day.date">
                                    <button type="button" @click="day.available && pickDate(day.date)"
                                        :disabled="!day.available"
                                        :title="day.reason || ''"
                                        :class="!day.available
                                            ? 'bg-amber-900/10 text-amber-100/20 border-amber-900/20 cursor-not-allowed'
                                            : (selectedDate === day.date
                                                ? 'bg-gradient-to-br from-amber-600 to-amber-500 text-stone-900 border-amber-500 shadow-md'
                                                : 'bg-transparent text-amber-100/70 border-amber-800/30 hover:border-amber-600')"
                                        class="flex-shrink-0 w-16 py-2 border rounded-xl text-center transition snap-start">
                                        <span class="block text-[10px] font-medium opacity-80" x-text="day.dayLabel"></span>
                                        <span class="block text-xl font-black leading-none mt-0.5" x-text="day.dayOfMonth"></span>
                                        <span class="block text-[10px] mt-0.5 opacity-70" x-show="day.available" x-text="day.monthLabel"></span>
                                        {{-- "Not Available" ribbon (item 4.1) — replaces silently hiding the chip. --}}
                                        <span x-show="!day.available" class="block text-[8px] leading-tight mt-0.5 font-semibold text-red-400/70">{{ __('availability.not_available') }}</span>
                                    </button>
                                </template>
                            </div>
                        </div>

                        {{-- Slots --}}
                        <div x-show="selectedDate" x-transition>
                            <div x-show="loading" class="text-amber-100/40 text-sm py-4">
                                <svg class="animate-spin h-5 w-5 inline mr-2" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                </svg>
                                {{ __('seva.loading') }}
                            </div>

                            {{-- Blackout message --}}
                            <div x-show="!loading && blackout" class="text-sm py-4 px-4 bg-red-900/20 rounded-lg border border-red-800/30">
                                <span class="text-red-400 font-semibold">{{ __('seva.not_available_date') }}</span>
                                <span x-show="blackoutReason" class="block text-red-300/60 mt-1 text-xs" x-text="blackoutReason"></span>
                            </div>

                            {{-- Unavailable message (outside acceptance period) --}}
                            <div x-show="!loading && !blackout && unavailableMessage" class="text-amber-100/40 text-sm py-4">
                                <span x-text="unavailableMessage"></span>
                            </div>

                            <div x-show="!loading && !blackout && !unavailableMessage && slots.length === 0 && booked.length === 0" class="text-amber-100/40 text-sm py-4">
                                {{ __('seva.no_slots') }}
                            </div>

                            {{-- Time-slot mode: pick a time --}}
                            <div x-show="!loading && !blackout && slotType === 'time_slots' && (slots.length > 0 || booked.length > 0)">
                                <p class="text-sm text-amber-100/50 mb-2">
                                    {{ __('seva.available_time') }}
                                    <span x-show="slotDuration" class="text-amber-100/30" x-text="'(' + slotDuration + ' {{ __('seva.minutes') }})'"></span>
                                </p>
                                <div class="flex flex-wrap gap-2">
                                    <template x-for="slot in slots" :key="slot">
                                        <button @click="selectedSlot = slot"
                                            :class="selectedSlot === slot ? 'bg-gradient-to-r from-amber-600 to-amber-500 text-stone-900 border-amber-500 font-bold' : 'bg-transparent text-amber-100/60 border-amber-800/30 hover:border-amber-600'"
                                            class="px-4 py-2 border rounded-lg text-sm font-medium transition"
                                            x-text="slot">
                                        </button>
                                    </template>
                                    {{-- Unavailable slots (fully booked, elapsed or
                                         inside the cut-off) now render with an
                                         explicit "Not Available" label + reason
                                         tooltip instead of a bare struck-through
                                         chip (item 4.1). --}}
                                    <template x-for="slot in unavailableSlots()" :key="'b-' + slot.time">
                                        <button disabled :title="slot.reason || ''"
                                            class="px-4 py-2 border border-amber-900/20 rounded-lg text-sm font-medium bg-amber-900/10 text-amber-100/20 cursor-not-allowed text-center">
                                            <span class="block line-through" x-text="slot.time"></span>
                                            <span class="block text-[9px] leading-tight font-semibold text-red-400/70">{{ __('availability.not_available') }}</span>
                                        </button>
                                    </template>
                                </div>
                            </div>

                            {{-- Full-day / full-week mode: the day or week IS the slot --}}
                            <div x-show="!loading && !blackout && slotType !== 'time_slots'">
                                <template x-if="slots.length > 0">
                                    <button @click="selectedSlot = slots[0]"
                                        :class="selectedSlot ? 'bg-gradient-to-r from-amber-600 to-amber-500 text-stone-900 border-amber-500 font-bold' : 'bg-transparent text-amber-100/60 border-amber-800/30 hover:border-amber-600'"
                                        class="w-full px-4 py-3 border rounded-lg text-sm font-semibold transition"
                                        x-text="slotType === 'full_week' ? '{{ __('seva.book_full_week') }}' : ('{{ __('seva.book') }} ' + formatSelectedDate())">
                                    </button>
                                </template>
                                <template x-if="slots.length === 0 && booked.length > 0">
                                    <div class="w-full px-4 py-3 border border-amber-900/20 rounded-lg text-sm bg-amber-900/10 text-amber-100/40 text-center"
                                        x-text="slotType === 'full_week' ? '{{ __('seva.full_week_booked') }}' : '{{ __('seva.full_day_booked') }}'">
                                    </div>
                                </template>
                            </div>
                        </div>

                        {{-- Additional Fields — appear once EITHER a date or a
                             product has been chosen. For sevas without linked
                             products selectedProductId is always null, so this
                             behaves exactly as before (date-gated). --}}
                        <div x-show="selectedDate || selectedProductId" class="mt-4 space-y-3">
                            <div>
                                <label class="block text-sm font-medium text-amber-600 mb-1">{{ __('seva.name_label') }}</label>
                                <input type="text" x-model="devoteeName" placeholder="{{ __('seva.name_placeholder') }}"
                                    class="w-full bg-transparent border-amber-800/30 rounded-lg text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-amber-600/20">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-amber-600 mb-1">{{ __('seva.sankalp_label') }}</label>
                                <textarea x-model="sankalp" rows="2" placeholder="{{ __('seva.sankalp_placeholder') }}"
                                    class="w-full bg-transparent border-amber-800/30 rounded-lg text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-amber-600/20"></textarea>
                            </div>
                        </div>

                        {{-- Book Button (whole panel is auth-gated above) --}}
                        <div class="mt-6">
                            <form method="POST" action="{{ route('seva.book', $seva) }}">
                                @csrf
                                <input type="hidden" name="booking_date" :value="selectedDate">
                                <input type="hidden" name="slot_time" :value="selectedSlot">
                                <input type="hidden" name="quantity" value="1">
                                <input type="hidden" name="devotee_name_for_seva" :value="devoteeName">
                                <input type="hidden" name="sankalp" :value="sankalp">
                                <input type="hidden" name="selected_product_id" :value="selectedProductId">
                                <input type="hidden" name="selected_variant_label" :value="selectedVariant">
                                <button type="submit"
                                    :disabled="!canBook()"
                                    class="w-full px-8 py-3 btn-divine disabled:opacity-40 disabled:cursor-not-allowed">
                                    {{ __('seva.book_for') }}<span x-text="displayPrice()"></span>
                                </button>
                            </form>
                        </div>
                    </div>
                    @endguest
                @else
                    <div class="card-sacred p-6">
                        <a href="#" class="flex items-center justify-center w-full px-8 py-3 btn-divine">
                            {{ __('seva.donate_for_seva') }}
                        </a>
                    </div>
                @endif

            </div>
        </div>

    </div>
</div>

@push('scripts')
<script>
function slotPicker(sevaId) {
    const config = @json($seva->getResolvedSlotConfig());
    const basePrice = {{ (float) $seva->price }};
    const hasProductSelection = @json($linkedProducts->isNotEmpty());
    @php
        $productsForJs = $linkedProducts->mapWithKeys(fn ($p) => [$p->id => [
            'id' => $p->id,
            'price' => (float) $p->price,
            'has_variants' => (bool) $p->has_variants,
            'variants' => ($p->has_variants && ! empty($p->variants))
                ? collect($p->variants)->map(fn ($v) => [
                    'label' => $v['label'] ?? '',
                    'price' => (float) ($v['price'] ?? 0),
                    // Untracked products: every variant available.
                    'in_stock' => ! $p->track_stock || ((int) ($v['stock'] ?? 0)) > 0,
                ])->values()
                : [],
        ]]);
    @endphp
    const products = @json($productsForJs);

    // Gujarati day-of-week labels — week starts Monday = index 0 for
    // Carbon parity, but JS Date.getDay() returns Sun=0..Sat=6.
    const dayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const monthLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    // Year/Month picker horizon: current month … same month +5 years,
    // mirroring the available-dates API's accepted range.
    const _now = new Date();
    const currentYear = _now.getFullYear();
    const currentMonthNum = _now.getMonth() + 1;
    const pageLang = document.documentElement.lang || 'en';
    const localizedMonthName = m => new Date(2000, m - 1, 1).toLocaleDateString(pageLang, { month: 'long' });

    return {
        sevaId: sevaId,
        selectedDate: '',
        selectedSlot: '',
        slots: [],
        booked: [],
        slotType: config.slot_type || 'time_slots',
        loading: false,
        blackout: false,
        blackoutReason: '',
        unavailableMessage: '',
        slotDuration: config.slot_duration_minutes || 0,
        selectedProductId: null,
        selectedVariant: '',
        devoteeName: '',
        sankalp: '',

        currentProduct() {
            return this.selectedProductId ? (products[this.selectedProductId] || null) : null;
        },
        needsVariant() {
            const p = this.currentProduct();
            return !!(p && p.has_variants);
        },
        unitPrice() {
            // Zero-priced product/variant = the option doesn't set the
            // price; the seva's own price (basePrice) stays in effect.
            const p = this.currentProduct();
            if (!p) return basePrice;
            if (p.has_variants) {
                const v = p.variants.find(x => x.label === this.selectedVariant);
                const vp = v ? v.price : p.price;
                return vp > 0 ? vp : basePrice;
            }
            return p.price > 0 ? p.price : basePrice;
        },
        displayPrice() {
            return '₹' + Number(this.unitPrice()).toLocaleString('en-IN');
        },
        canBook() {
            // Date and product are validated INDEPENDENTLY — neither gates
            // the other in the UI, so neither may gate the other here.
            if (!this.selectedDate) return false;
            if (this.blackout) return false;
            // Time-slot sevas need an actual slot; full-day/full-week sevas
            // have their slot_time forced server-side (SevaWebController::book),
            // so a picked date is enough there.
            if (this.slotType === 'time_slots' && !this.selectedSlot) return false;
            if (hasProductSelection) {
                if (!this.selectedProductId) return false;
                if (this.needsVariant() && !this.selectedVariant) return false;
            }
            return true;
        },

        // Date carousel state — populated from the
        // /sevas/{id}/available-dates endpoint. Since item 4.1 it holds
        // EVERY date of the month (days_detail), each carrying
        // available/reason, so unbookable dates render disabled with a
        // "Not Available" ribbon instead of vanishing.
        availableDates: [],
        slotDetails: [],
        datesLoading: false,
        findingNext: false,
        nextAvailableNote: '',
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
            this.fetchAvailableDates();
        },

        onMonthChange() {
            this.fetchAvailableDates();
        },

        init() {
            this.fetchAvailableDates();
        },

        async fetchAvailableDates(keepSelection) {
            this.datesLoading = true;
            // A month switch invalidates the current selection + slots.
            if (!keepSelection) {
                this.selectedDate = '';
                this.selectedSlot = '';
                this.slots = [];
                this.booked = [];
                this.slotDetails = [];
            }
            const month = `${this.selectedYear}-${String(this.selectedMonth).padStart(2, '0')}`;
            try {
                const res = await fetch(`/api/v1/sevas/${this.sevaId}/available-dates?month=${month}`);
                const json = await res.json();
                const detail = json.data?.days_detail;
                if (Array.isArray(detail) && detail.length > 0) {
                    this.availableDates = detail.map(d => this.decorateDate(d.date, d.available !== false, d.reason));
                } else {
                    // Older server (or an empty month): fall back to the
                    // legacy bookable-only list.
                    const isoList = json.data?.dates || [];
                    this.availableDates = isoList.map(iso => this.decorateDate(iso, true, null));
                }
            } catch (e) {
                this.availableDates = [];
            }
            this.datesLoading = false;
        },

        openDateCount() {
            return this.availableDates.filter(d => d.available).length;
        },

        // Unavailable slot chips. Prefers the new slot_details payload
        // (carries the reason); falls back to the legacy `booked` list.
        unavailableSlots() {
            if (Array.isArray(this.slotDetails) && this.slotDetails.length > 0) {
                return this.slotDetails.filter(s => !s.available);
            }
            return (this.booked || []).map(t => ({ time: t, reason: null }));
        },

        // Item 4.4 — one request replaces a month-by-month client scan.
        async findNextAvailable() {
            if (this.findingNext) return;
            this.findingNext = true;
            this.nextAvailableNote = '';
            try {
                const res = await fetch(`/api/v1/sevas/${this.sevaId}/next-available`);
                const json = await res.json();
                const d = json.data;
                if (!d || d.found !== true || !d.date) {
                    this.nextAvailableNote = @js(__('availability.next_available_none'));
                } else {
                    const [y, m] = d.date.split('-').map(Number);
                    this.selectedYear = y;
                    this.selectedMonth = m;
                    await this.fetchAvailableDates(true);
                    this.selectedDate = d.date;
                    await this.fetchSlots();
                    if (d.slot_time) this.selectedSlot = d.slot_time;
                }
            } catch (e) {
                this.nextAvailableNote = @js(__('availability.next_available_none'));
            }
            this.findingNext = false;
        },

        decorateDate(iso, available, reason) {
            // iso = 'YYYY-MM-DD'. Construct as local-time midnight so
            // getDay() / getDate() / getMonth() return the temple-local
            // values regardless of the browser's timezone.
            const [y, m, d] = iso.split('-').map(Number);
            const date = new Date(y, m - 1, d);
            return {
                date: iso,
                dayLabel: dayLabels[date.getDay()],
                dayOfMonth: date.getDate(),
                monthLabel: monthLabels[date.getMonth()],
                available: available !== false,
                reason: reason || null,
            };
        },

        // 'YYYY-MM-DD' → '13 July' (full month name).
        formatSelectedDate() {
            if (!this.selectedDate) return '';
            const [y, m, d] = this.selectedDate.split('-').map(Number);
            const months = ['January', 'February', 'March', 'April', 'May', 'June',
                'July', 'August', 'September', 'October', 'November', 'December'];
            return d + ' ' + months[m - 1];
        },

        pickDate(iso) {
            this.selectedDate = iso;
            this.fetchSlots();
        },

        async fetchSlots() {
            if (!this.selectedDate) return;
            this.loading = true;
            this.selectedSlot = '';
            this.blackout = false;
            this.blackoutReason = '';
            this.unavailableMessage = '';
            try {
                const res = await fetch(`/api/v1/sevas/${this.sevaId}/slots?date=${this.selectedDate}`);
                const json = await res.json();
                this.slots = json.data?.slots || [];
                this.booked = json.data?.booked || [];
                this.slotDetails = json.data?.slot_details || [];
                this.slotType = json.data?.slot_type || this.slotType || 'time_slots';
                this.blackout = json.data?.blackout || false;
                this.blackoutReason = json.data?.blackout_reason || '';
                this.unavailableMessage = json.data?.message || '';
                this.slotDuration = json.data?.slot_duration_minutes || config.slot_duration_minutes || 0;
            } catch (e) {
                this.slots = [];
                this.booked = [];
                this.slotDetails = [];
            }
            this.loading = false;
        }
    };
}
</script>
@endpush
@endsection
