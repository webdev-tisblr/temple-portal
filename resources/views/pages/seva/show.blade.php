@extends('layouts.app')

@section('content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8 bg-temple">

    <x-breadcrumb
        :items="[
            ['label' => __('home.seva_puja'), 'url' => route('seva.index')],
            ['label' => $seva->name],
        ]"
        class="mb-6" />

    <div class="card-sacred overflow-hidden">
        {{-- Image --}}
        <div class="aspect-video bg-amber-900/20 flex items-center justify-center">
            @if($seva->image_path)
                <img src="{{ image_url($seva->image_path) }}" alt="{{ $seva->name }}" class="w-full h-full object-cover">
            @else
                <span class="text-8xl">🙏</span>
            @endif
        </div>

        <div class="p-6 sm:p-8">
            {{-- Category Badge --}}
            <span class="inline-block px-3 py-1 text-xs font-medium rounded-full mb-3 bg-amber-900/30 text-amber-400">
                {{ $seva->getRawOriginal('category') }}
            </span>

            <h1 class="divine-heading text-2xl sm:text-3xl">{{ $seva->name }}</h1>

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

            {{-- Booking Section --}}
            @if($seva->requires_booking)
                <div class="mt-8 border-t border-amber-900/20 pt-6" x-data="slotPicker({{ $seva->id }})">
                    <h2 class="text-lg font-semibold text-gold mb-4">{{ __('seva.choose_date_time') }}</h2>

                    {{-- Date picker — horizontal chip carousel.
                         Mirrors the mobile seva detail screen exactly:
                         only bookable dates appear (returned by
                         /sevas/{id}/available-dates), each chip shows
                         day label + day number + month, and the
                         selected chip is highlighted in saffron. --}}
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-amber-600 mb-2">{{ __('seva.choose_date') }}</label>

                        <div x-show="datesLoading" class="text-amber-100/40 text-xs py-2">
                            {{ __('seva.loading_dates') }}
                        </div>

                        <div x-show="!datesLoading && availableDates.length === 0" class="text-sm py-3 px-4 bg-amber-900/10 border border-amber-800/30 rounded-lg text-amber-100/60">
                            {{ __('seva.no_dates') }}
                        </div>

                        <div x-show="!datesLoading && availableDates.length > 0"
                             class="flex gap-2 overflow-x-auto pb-2 -mx-1 px-1 snap-x"
                             style="scrollbar-width: thin;">
                            <template x-for="day in availableDates" :key="day.date">
                                <button type="button" @click="pickDate(day.date)"
                                    :class="selectedDate === day.date
                                        ? 'bg-gradient-to-br from-amber-600 to-amber-500 text-stone-900 border-amber-500 shadow-md'
                                        : 'bg-transparent text-amber-100/70 border-amber-800/30 hover:border-amber-600'"
                                    class="flex-shrink-0 w-16 py-2 border rounded-xl text-center transition snap-start">
                                    <span class="block text-[10px] font-medium uppercase tracking-wide opacity-80" x-text="day.dayLabel"></span>
                                    <span class="block text-xl font-black leading-none mt-0.5" x-text="day.dayOfMonth"></span>
                                    <span class="block text-[10px] mt-0.5 opacity-70" x-text="day.monthLabel"></span>
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
                                <template x-for="slot in booked" :key="'b-' + slot">
                                    <button disabled class="px-4 py-2 border border-amber-900/20 rounded-lg text-sm font-medium bg-amber-900/10 text-amber-100/20 cursor-not-allowed line-through" x-text="slot">
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
                                    x-text="slotType === 'full_week' ? '{{ __('seva.book_full_week') }}' : '{{ __('seva.book_full_day') }}'">
                                </button>
                            </template>
                            <template x-if="slots.length === 0 && booked.length > 0">
                                <div class="w-full px-4 py-3 border border-amber-900/20 rounded-lg text-sm bg-amber-900/10 text-amber-100/40 text-center"
                                    x-text="slotType === 'full_week' ? '{{ __('seva.full_week_booked') }}' : '{{ __('seva.full_day_booked') }}'">
                                </div>
                            </template>
                        </div>
                    </div>

                    {{-- Product Selection --}}
                    @if($linkedProducts->isNotEmpty())
                        <div class="mt-6 mb-4" x-show="selectedDate">
                            <label class="block text-sm font-medium text-amber-600 mb-3">{{ $seva->getProductSelectionLabel() }}</label>
                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                                @foreach($linkedProducts as $lp)
                                    <button type="button"
                                        @click="selectedProductId = {{ $lp->id }}; selectedVariant = ''"
                                        :class="selectedProductId === {{ $lp->id }} ? 'ring-2 ring-amber-500 border-amber-500' : 'border-amber-800/30 hover:border-amber-600'"
                                        class="border rounded-xl overflow-hidden transition text-left bg-amber-900/10">
                                        <div class="aspect-square bg-amber-900/20 overflow-hidden">
                                            @if($lp->image_path)
                                                <img src="{{ image_url($lp->image_path) }}" alt="{{ $lp->name }}" class="w-full h-full object-cover">
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
                                                x-text="v.label + ' — ₹' + Number(v.price).toLocaleString('en-IN')">
                                            </button>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    @endif

                    {{-- Additional Fields --}}
                    <div x-show="selectedDate" class="mt-4 space-y-3">
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

                    {{-- Book Button --}}
                    <div class="mt-6">
                        @auth('devotee')
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
                                    class="w-full sm:w-auto px-8 py-3 btn-divine disabled:opacity-40 disabled:cursor-not-allowed">
                                    {{ __('seva.book_for') }}<span x-text="displayPrice()"></span>
                                </button>
                            </form>
                        @else
                            <a href="{{ route('login') }}" class="inline-flex items-center px-8 py-3 btn-divine">
                                {{ __('seva.login_to_book') }}
                            </a>
                        @endauth
                    </div>
                </div>
            @else
                <div class="mt-8 border-t border-amber-900/20 pt-6">
                    <a href="#" class="inline-flex items-center px-8 py-3 btn-divine">
                        {{ __('seva.donate_for_seva') }}
                    </a>
                </div>
            @endif
        </div>
    </div>
</div>

@push('scripts')
<script>
function slotPicker(sevaId) {
    const config = @json($seva->getResolvedSlotConfig());
    const basePrice = {{ (float) $seva->price }};
    const hasProductSelection = @json($linkedProducts->isNotEmpty());
    const products = @json(
        $linkedProducts->mapWithKeys(fn ($p) => [$p->id => [
            'id' => $p->id,
            'price' => (float) $p->price,
            'has_variants' => (bool) $p->has_variants,
            'variants' => ($p->has_variants && ! empty($p->variants))
                ? collect($p->variants)->map(fn ($v) => [
                    'label' => $v['label'] ?? '',
                    'price' => (float) ($v['price'] ?? 0),
                    'in_stock' => ((int) ($v['stock'] ?? 0)) > 0,
                ])->values()
                : [],
        ]])
    );

    // Gujarati day-of-week labels — week starts Monday = index 0 for
    // Carbon parity, but JS Date.getDay() returns Sun=0..Sat=6.
    const dayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const monthLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

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
            const p = this.currentProduct();
            if (!p) return basePrice;
            if (p.has_variants) {
                const v = p.variants.find(x => x.label === this.selectedVariant);
                return v ? v.price : p.price;
            }
            return p.price;
        },
        displayPrice() {
            return '₹' + Number(this.unitPrice()).toLocaleString('en-IN');
        },
        canBook() {
            if (!this.selectedDate) return false;
            if (hasProductSelection) {
                if (!this.selectedProductId) return false;
                if (this.needsVariant() && !this.selectedVariant) return false;
            }
            return true;
        },

        // Date carousel state — populated from the
        // /sevas/{id}/available-dates endpoint so blackouts, fully
        // booked dates and today's-elapsed-slots are hidden.
        availableDates: [],
        datesLoading: false,

        init() {
            this.fetchAvailableDates();
        },

        async fetchAvailableDates() {
            this.datesLoading = true;
            try {
                const res = await fetch(`/api/v1/sevas/${this.sevaId}/available-dates?days=30`);
                const json = await res.json();
                const isoList = json.data?.dates || [];
                this.availableDates = isoList.map(iso => this.decorateDate(iso));
            } catch (e) {
                this.availableDates = [];
            }
            this.datesLoading = false;
        },

        decorateDate(iso) {
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
            };
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
                this.slotType = json.data?.slot_type || this.slotType || 'time_slots';
                this.blackout = json.data?.blackout || false;
                this.blackoutReason = json.data?.blackout_reason || '';
                this.unavailableMessage = json.data?.message || '';
                this.slotDuration = json.data?.slot_duration_minutes || config.slot_duration_minutes || 0;
            } catch (e) {
                this.slots = [];
                this.booked = [];
            }
            this.loading = false;
        }
    };
}
</script>
@endpush
@endsection
