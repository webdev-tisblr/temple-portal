@extends('layouts.app')

@section('content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8 bg-temple" x-data="donationForm()">

    <div class="text-center mb-8">
        <h1 class="divine-heading text-3xl">{{ __('nav.donate') }}</h1>
        <p class="divine-subtext mt-2">{{ __('donation.subtitle') }}</p>
    </div>

    @if($errors->any())
        <div class="bg-red-950/30 border border-red-800/30 text-red-300 px-4 py-3 rounded-lg mb-6 text-sm">
            @foreach($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <div class="card-sacred p-6 sm:p-8">

        {{-- Preset Amounts.
             Chip click writes the preset into the amount input below
             and into the hidden `amount` (the single source of truth
             for the form). Typing into the input is also reflected back
             into `amount`, so admins/devotees can pick a preset and
             then override it. Mirrors the mobile donate screen. --}}
        <div class="mb-6">
            <label class="block text-sm font-medium text-amber-600 mb-3">{{ __('donation.choose_amount') }}</label>
            <div class="grid grid-cols-3 gap-3">
                @foreach([101, 501, 1100, 2100, 5100, 11000] as $preset)
                    <button type="button"
                        @click="selectPreset({{ $preset }})"
                        :class="amount === {{ $preset }} ? 'bg-gradient-to-r from-amber-600 to-amber-500 text-stone-900 border-amber-500 font-bold' : 'bg-transparent text-amber-100/60 border-amber-800/30 hover:border-amber-600'"
                        class="py-3 border rounded-lg text-sm font-semibold transition">
                        ₹{{ number_format($preset) }}
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Custom Amount.
             Filled automatically when a preset chip is clicked; typing
             here overrides the chip selection. --}}
        <div class="mb-6">
            <label class="block text-sm font-medium text-amber-600 mb-1">{{ __('common.amount') }}</label>
            <div class="flex">
                <span class="inline-flex items-center px-3 rounded-l-lg border border-r-0 border-amber-800/30 bg-amber-900/20 text-amber-500 font-medium">₹</span>
                <input type="number" min="1" placeholder="{{ __('donation.enter_amount') }}"
                    x-model="customAmount"
                    @input="amount = customAmount ? parseInt(customAmount) : 0"
                    class="flex-1 block w-full rounded-r-lg bg-transparent border-amber-800/30 text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-amber-600/20 text-lg">
            </div>
        </div>

        {{-- Donation Type (Dynamic) --}}
        <div class="mb-6">
            <label class="block text-sm font-medium text-amber-600 mb-1">{{ __('donation.type') }}</label>
            <select x-model="selectedTypeId" @change="onTypeChange()" class="w-full bg-transparent border-amber-800/30 rounded-lg text-amber-100 focus:border-amber-600 focus:ring-amber-600/20">
                <option value="" class="bg-stone-900">{{ __('donation.choose_type') }}</option>
                @foreach($donationTypes as $type)
                    <option value="{{ $type->id }}" class="bg-stone-900">{{ $type->name_gu }}</option>
                @endforeach
            </select>
        </div>

        {{-- Dynamic Extra Fields placeholder — actual fields rendered inside the form below --}}

        {{-- Purpose --}}
        <div class="mb-6">
            <label class="block text-sm font-medium text-amber-600 mb-1">{{ __('donation.purpose') }}</label>
            <input type="text" x-model="purpose" placeholder="{{ __('donation.purpose_placeholder') }}"
                class="w-full bg-transparent border-amber-800/30 rounded-lg text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-amber-600/20">
        </div>

        {{-- Anonymous --}}
        <div class="mb-6">
            <label class="flex items-center gap-2">
                <input type="checkbox" x-model="anonymous" class="rounded border-amber-800/40 bg-transparent text-amber-500 focus:ring-amber-600/20">
                <span class="text-sm text-amber-100/60">{{ __('donation.gupt_daan') }}</span>
            </label>
        </div>

        {{-- Submit --}}
        @auth('devotee')
            <form method="POST" action="{{ route('donate.create') }}" enctype="multipart/form-data" x-ref="donationForm">
                @csrf
                <input type="hidden" name="amount" :value="amount">
                <input type="hidden" name="donation_type" :value="donationType">
                <input type="hidden" name="donation_type_id" :value="selectedTypeId || ''">
                <input type="hidden" name="purpose" :value="purpose">
                <input type="hidden" name="anonymous" :value="anonymous ? 1 : 0">

                {{-- Dynamic Extra Fields (inside form so they submit properly) --}}
                <template x-if="currentExtraFields.length > 0">
                    <div class="mb-6 space-y-4 p-4 border border-amber-800/20 rounded-lg bg-amber-900/10">
                        <p class="text-xs text-amber-500 font-medium">{{ __('donation.extra_info') }}</p>
                        <template x-for="(field, index) in currentExtraFields" :key="field.key">
                            <div>
                                <label class="block text-sm font-medium text-amber-600 mb-1" x-text="field.label_gu || field.label_en"></label>
                                <input x-show="field.type === 'text'" type="text"
                                    :name="field.type === 'text' ? 'extra_data[' + field.key + ']' : ''"
                                    :required="field.type === 'text' && field.required"
                                    class="w-full bg-transparent border-amber-800/30 rounded-lg text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-amber-600/20">
                                <input x-show="field.type === 'number'" type="number"
                                    :name="field.type === 'number' ? 'extra_data[' + field.key + ']' : ''"
                                    :required="field.type === 'number' && field.required"
                                    class="w-full bg-transparent border-amber-800/30 rounded-lg text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-amber-600/20">
                                <input x-show="field.type === 'date'" type="date"
                                    :name="field.type === 'date' ? 'extra_data[' + field.key + ']' : ''"
                                    :required="field.type === 'date' && field.required"
                                    class="w-full bg-transparent border-amber-800/30 rounded-lg text-amber-100 focus:border-amber-600 focus:ring-amber-600/20">
                                <input x-show="field.type === 'image'" type="file"
                                    :name="field.type === 'image' ? 'extra_data[' + field.key + ']' : ''"
                                    :required="field.type === 'image' && field.required"
                                    accept="image/*"
                                    class="w-full bg-transparent border-amber-800/30 rounded-lg text-amber-100 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:bg-amber-900/40 file:text-amber-400">
                                <textarea x-show="field.type === 'textarea'"
                                    :name="field.type === 'textarea' ? 'extra_data[' + field.key + ']' : ''"
                                    :required="field.type === 'textarea' && field.required" rows="3"
                                    class="w-full bg-transparent border-amber-800/30 rounded-lg text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-amber-600/20"></textarea>
                            </div>
                        </template>
                    </div>
                </template>

                <button type="submit"
                    :disabled="!amount || amount < 1"
                    class="w-full py-3 btn-divine disabled:opacity-40 disabled:cursor-not-allowed text-lg">
                    ₹<span x-text="amount ? amount.toLocaleString('en-IN') : '0'"></span> {{ __('nav.donate') }}
                </button>
            </form>
        @else
            <a href="{{ route('login') }}" class="block w-full text-center py-3 btn-divine text-lg">
                {{ __('donation.login_to_donate') }}
            </a>
        @endauth
    </div>

    {{-- Active Campaigns --}}
    @if($campaigns->isNotEmpty())
        <div class="mt-10">
            <h2 class="text-xl font-bold text-gold mb-4">{{ __('donation.active_campaigns') }}</h2>
            @foreach($campaigns as $campaign)
                <div class="card-sacred p-5 mb-4">
                    <h3 class="font-semibold text-amber-100/70">{{ $campaign->title }}</h3>
                    @if($campaign->description)
                        <p class="text-sm text-amber-100/40 mt-1">{{ $campaign->description }}</p>
                    @endif
                    <div class="mt-3">
                        @php $pct = $campaign->goal_amount > 0 ? min(100, round(($campaign->raised_amount / $campaign->goal_amount) * 100)) : 0; @endphp
                        <div class="w-full bg-amber-900/30 rounded-full h-3">
                            <div class="bg-gradient-to-r from-amber-600 to-amber-400 h-3 rounded-full transition-all" style="width: {{ $pct }}%"></div>
                        </div>
                        <div class="flex justify-between text-xs text-amber-100/40 mt-1">
                            <span>₹{{ number_format((float) $campaign->raised_amount) }} {{ __('donation.raised') }}</span>
                            <span>₹{{ number_format((float) $campaign->goal_amount) }} {{ __('donation.goal') }}</span>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>

@push('scripts')
<script>
function donationForm() {
    return {
        amount: 1100,
        customAmount: '1100',
        selectedTypeId: '',
        donationType: 'general',
        purpose: '',
        anonymous: false,
        currentExtraFields: [],

        // Donation types data from server
        donationTypesData: @json($donationTypesJs),

        // Chip click — write the preset into both the canonical amount
        // and the visible custom input so the value is editable.
        selectPreset(preset) {
            this.amount = preset;
            this.customAmount = String(preset);
        },

        onTypeChange() {
            const selected = this.donationTypesData.find(t => t.id == this.selectedTypeId);
            if (selected) {
                this.donationType = selected.slug;
                this.currentExtraFields = Array.isArray(selected.extra_fields) ? selected.extra_fields : [];
            } else {
                this.donationType = 'general';
                this.currentExtraFields = [];
            }
        },

        // submitForm no longer needed — extra fields are now inside the form
    };
}
</script>
@endpush
@endsection
