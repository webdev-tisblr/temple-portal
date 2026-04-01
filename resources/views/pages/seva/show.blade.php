@extends('layouts.app')

@section('content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8 bg-temple">

    {{-- Breadcrumb --}}
    <nav class="text-sm text-amber-100/30 mb-6">
        <a href="{{ route('home') }}" class="hover:text-gold transition">મુખ્ય પૃષ્ઠ</a>
        <span class="mx-2">/</span>
        <a href="{{ route('seva.index') }}" class="hover:text-gold transition">સેવા</a>
        <span class="mx-2">/</span>
        <span class="text-gold">{{ $seva->name }}</span>
    </nav>

    <div class="card-sacred overflow-hidden">
        {{-- Image --}}
        <div class="aspect-video bg-amber-900/20 flex items-center justify-center">
            @if($seva->image_path)
                <img src="{{ asset('storage/' . $seva->image_path) }}" alt="{{ $seva->name }}" class="w-full h-full object-cover">
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
                    <span class="text-sm text-amber-100/40">ન્યૂનતમ રકમ:</span>
                    <span class="text-2xl font-bold text-gold ml-1">₹{{ number_format((float) $seva->min_price) }}</span>
                @else
                    <span class="text-2xl font-bold text-gold">₹{{ number_format((float) $seva->price) }}</span>
                @endif
            </div>

            {{-- Description --}}
            @if($seva->description)
                <div class="mt-4 text-amber-100/60 leading-relaxed prose prose-invert prose-sm max-w-none">
                    {!! nl2br(e($seva->description)) !!}
                </div>
            @endif

            {{-- Booking Section --}}
            @if($seva->requires_booking)
                <div class="mt-8 border-t border-amber-900/20 pt-6" x-data="slotPicker({{ $seva->id }})">
                    <h2 class="text-lg font-semibold text-gold mb-4">તારીખ અને સમય પસંદ કરો</h2>

                    {{-- Date Picker --}}
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-amber-600 mb-1">તારીખ</label>
                        <input type="date"
                            :min="minDate"
                            :max="maxDate"
                            x-model="selectedDate"
                            @change="fetchSlots()"
                            class="w-full sm:w-auto bg-transparent border-amber-800/30 rounded-lg text-amber-100 focus:border-amber-600 focus:ring-amber-600/20">
                    </div>

                    {{-- Slots --}}
                    <div x-show="selectedDate" x-transition>
                        <div x-show="loading" class="text-amber-100/40 text-sm py-4">
                            <svg class="animate-spin h-5 w-5 inline mr-2" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                            </svg>
                            લોડ થઈ રહ્યું છે...
                        </div>

                        <div x-show="!loading && slots.length === 0 && booked.length === 0" class="text-amber-100/40 text-sm py-4">
                            આ સેવા માટે કોઈ સમય સ્લોટ કોન્ફિગર નથી.
                        </div>

                        <div x-show="!loading && (slots.length > 0 || booked.length > 0)">
                            <p class="text-sm text-amber-100/50 mb-2">ઉપલબ્ધ સમય:</p>
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
                    </div>

                    {{-- Additional Fields --}}
                    <div x-show="selectedDate" class="mt-4 space-y-3">
                        <div>
                            <label class="block text-sm font-medium text-amber-600 mb-1">સેવા માટે નામ (વૈકલ્પિક)</label>
                            <input type="text" x-model="devoteeName" placeholder="તમારું અથવા પરિવારનું નામ"
                                class="w-full bg-transparent border-amber-800/30 rounded-lg text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-amber-600/20">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-amber-600 mb-1">ગોત્ર (વૈકલ્પિક)</label>
                            <input type="text" x-model="gotra" placeholder="ગોત્ર"
                                class="w-full bg-transparent border-amber-800/30 rounded-lg text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-amber-600/20">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-amber-600 mb-1">સંકલ્પ (વૈકલ્પિક)</label>
                            <textarea x-model="sankalp" rows="2" placeholder="તમારી મનોકામના / સંકલ્પ"
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
                                <input type="hidden" name="gotra" :value="gotra">
                                <input type="hidden" name="sankalp" :value="sankalp">
                                <button type="submit"
                                    :disabled="!selectedDate"
                                    class="w-full sm:w-auto px-8 py-3 btn-divine disabled:opacity-40 disabled:cursor-not-allowed">
                                    બુક કરો — ₹{{ number_format((float) $seva->price) }}
                                </button>
                            </form>
                        @else
                            <a href="{{ route('login') }}" class="inline-flex items-center px-8 py-3 btn-divine">
                                બુક કરવા લૉગિન કરો
                            </a>
                        @endauth
                    </div>
                </div>
            @else
                <div class="mt-8 border-t border-amber-900/20 pt-6">
                    <a href="#" class="inline-flex items-center px-8 py-3 btn-divine">
                        આ સેવા માટે દાન કરો
                    </a>
                </div>
            @endif
        </div>
    </div>
</div>

@push('scripts')
<script>
function slotPicker(sevaId) {
    const today = new Date();
    const maxDay = new Date();
    maxDay.setDate(today.getDate() + 30);

    return {
        sevaId: sevaId,
        selectedDate: '',
        selectedSlot: '',
        slots: [],
        booked: [],
        loading: false,
        devoteeName: '',
        gotra: '',
        sankalp: '',
        minDate: today.toISOString().split('T')[0],
        maxDate: maxDay.toISOString().split('T')[0],

        async fetchSlots() {
            if (!this.selectedDate) return;
            this.loading = true;
            this.selectedSlot = '';
            try {
                const res = await fetch(`/api/v1/sevas/${this.sevaId}/slots?date=${this.selectedDate}`);
                const json = await res.json();
                this.slots = json.data?.slots || [];
                this.booked = json.data?.booked || [];
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
