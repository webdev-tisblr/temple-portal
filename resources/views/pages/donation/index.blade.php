@extends('layouts.app')

@section('content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8 bg-temple" x-data="donationForm()">

    <div class="text-center mb-8">
        <h1 class="divine-heading text-3xl">{{ __('donation.title') }}</h1>
        <p class="divine-subtext mt-2">{{ __('donation.subtitle') }}</p>
    </div>

    @if($errors->any())
        <div class="bg-red-950/30 border border-red-800/30 text-red-300 px-4 py-3 rounded-lg mb-6 text-sm">
            @foreach($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    {{-- Campaign mode (?campaign={id}) — the form targets one campaign:
         header card here, hidden campaign_id + sub-cause picker below,
         and the donation-type dropdown is hidden. Entry point for the
         iOS app's campaign donate buttons (donations must happen on the
         web per App Store 3.2.2(iv)). --}}
    @if($selectedCampaign)
        <div class="card-sacred p-5 mb-6">
            <p class="text-xs text-amber-500 font-medium uppercase tracking-wide">{{ __('donation.donating_to') }}</p>
            <h2 class="text-lg font-bold text-amber-100/80 mt-1">{{ $selectedCampaign->title }}</h2>
            @if($selectedCampaign->description)
                <p class="text-sm text-amber-100/40 mt-1">{{ $selectedCampaign->description }}</p>
            @endif
            @php $pct = $selectedCampaign->goal_amount > 0 ? min(100, round(($selectedCampaign->raised_amount / $selectedCampaign->goal_amount) * 100)) : 0; @endphp
            <div class="w-full bg-amber-900/30 rounded-full h-3 mt-3">
                <div class="bg-gradient-to-r from-amber-600 to-amber-400 h-3 rounded-full transition-all" style="width: {{ $pct }}%"></div>
            </div>
            <div class="flex justify-between text-xs text-amber-100/40 mt-1">
                <span>{{ inr_units((float) $selectedCampaign->raised_amount) }} {{ __('donation.raised') }}</span>
                <span>{{ inr_units((float) $selectedCampaign->goal_amount) }} {{ __('donation.goal') }}</span>
            </div>
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
                        ₹{{ inr($preset) }}
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Custom Amount.
             Filled automatically when a preset chip is clicked; typing
             here overrides the chip selection. --}}
        <div class="mb-6">
            <label class="block text-sm font-medium text-amber-600 mb-1">{{ __('donation.amount_label') }}</label>
            <div class="flex">
                <span class="inline-flex items-center px-3 rounded-l-lg border border-r-0 border-amber-800/30 bg-amber-900/20 text-amber-500 font-medium">₹</span>
                <input type="number" min="1" placeholder="{{ __('donation.enter_amount') }}"
                    x-model="customAmount"
                    @input="amount = customAmount ? parseInt(customAmount) : 0"
                    class="flex-1 block w-full rounded-r-lg bg-transparent border-amber-800/30 text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-amber-600/20 text-lg">
            </div>
        </div>

        @if($selectedCampaign)
            {{-- Sub-cause picker replaces the type dropdown in campaign mode --}}
            @if($selectedCampaign->subCauses->isNotEmpty())
                <div class="mb-6">
                    <label class="block text-sm font-medium text-amber-600 mb-3">{{ __('donation.choose_cause') }}</label>
                    <div class="space-y-2">
                        <label class="flex items-center gap-3 p-3 border rounded-lg cursor-pointer transition"
                            :class="subCauseId === '' ? 'border-amber-600 bg-amber-900/20' : 'border-amber-800/30 hover:border-amber-600'">
                            <input type="radio" value="" x-model="subCauseId" class="border-amber-800/40 bg-transparent text-amber-500 focus:ring-amber-600/20">
                            <span class="text-sm text-amber-100/70">{{ __('donation.general_cause') }}</span>
                        </label>
                        @foreach($selectedCampaign->subCauses as $subCause)
                            <label class="flex items-center gap-3 p-3 border rounded-lg cursor-pointer transition"
                                :class="subCauseId === '{{ $subCause->id }}' ? 'border-amber-600 bg-amber-900/20' : 'border-amber-800/30 hover:border-amber-600'">
                                <input type="radio" value="{{ $subCause->id }}" x-model="subCauseId" class="border-amber-800/40 bg-transparent text-amber-500 focus:ring-amber-600/20">
                                <span class="text-sm text-amber-100/70">{{ $subCause->title }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif
        @else
        {{-- Donation Type (Dynamic) --}}
        <div class="mb-6">
            <label class="block text-sm font-medium text-amber-600 mb-1">{{ __('donation.type') }}</label>
            <select x-model="selectedTypeId" @change="onTypeChange()" class="w-full bg-transparent border-amber-800/30 rounded-lg text-amber-100 focus:border-amber-600 focus:ring-amber-600/20">
                <option value="" class="bg-stone-900">{{ __('donation.choose_type') }}</option>
                @foreach($donationTypes as $type)
                    <option value="{{ $type->id }}" class="bg-stone-900">{{ $type->name }}</option>
                @endforeach
            </select>
        </div>
        @endif

        {{-- Dynamic Extra Fields placeholder — actual fields rendered inside the form below --}}

        {{-- Gupt Daan (anonymity).
             Corrected 2026-08-10: this is the ONLY thing that makes a
             donation anonymous. It is entirely independent of the 80G
             checkbox below — a Gupt Daan donor with a PAN still receives
             their 80G receipt, and a donor with no PAN is still a named,
             ordinary donor on the public lists. The hint spells out what
             ticking it actually does, because "ગુપ્ત દાન" alone read to
             live testers as a donation category rather than a display
             choice. --}}
        <div class="mb-6">
            <label class="flex items-start gap-2 cursor-pointer">
                <input type="checkbox" x-model="anonymous" class="mt-1 rounded border-amber-800/40 bg-transparent text-amber-500 focus:ring-amber-600/20">
                <span>
                    <span class="text-sm text-amber-100/60">{{ __('donation.gupt_daan') }}</span>
                    <span class="block text-xs text-amber-100/30 mt-0.5">{{ __('donation.gupt_daan_hint') }}</span>
                </span>
            </label>
        </div>

        {{-- 80G request (item 5.4).
             The rule is strict: no valid PAN on the donor's profile means no
             80G receipt and no receipt number, whatever the amount. This
             checkbox is the donor's REQUEST; the server decides what can
             actually be issued.

             It says NOTHING about anonymity — declining an 80G receipt does
             not make the donation a Gupt Daan, and Gupt Daan does not
             withhold a receipt. The two boxes are independent.

             This lives on the WEB form on purpose — on iOS the donate flow
             IS this page (DonateGate forces the website for App Store
             3.2.2(iv)), so a prompt built only in Flutter would be bypassed
             by every iPhone donor. --}}
        @auth('devotee')
        <div class="mb-6">
            <label class="flex items-start gap-2">
                <input type="checkbox" x-model="wants80g" class="mt-1 rounded border-amber-800/40 bg-transparent text-amber-500 focus:ring-amber-600/20">
                <span>
                    <span class="text-sm text-amber-100/60">{{ __('donation.want_80g') }}</span>
                    @if($hasPan)
                        <span class="ml-2 text-xs text-emerald-400">✓ {{ __('donation.pan_on_file') }}</span>
                    @endif
                    <span class="block text-xs text-amber-100/30 mt-0.5">{{ __('donation.want_80g_hint') }}</span>
                </span>
            </label>

            {{-- The friendly half of the prompt. The binding guard in
                 DonationWebController::create is the half that actually
                 holds (it also covers a donor with JS disabled). --}}
            @unless($hasPan)
                <div x-show="wants80g" x-cloak
                    class="mt-3 rounded-lg border border-amber-700/40 bg-amber-900/20 px-4 py-3">
                    <p class="text-sm font-semibold text-amber-300">{{ __('donation.pan_required_title') }}</p>
                    <p class="text-xs text-amber-100/50 mt-1">{{ __('donation.pan_required_body') }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        {{-- Submits the form rather than linking straight to
                             /dashboard/profile. The server-side guard is what
                             records the return destination (SafeRedirect, the
                             same mechanism as post-login redirect, item 3.1),
                             and it rebuilds a /donate URL carrying the amount,
                             type and campaign — so saving the PAN
                             lands the donor back on a form that is already
                             filled in, not an empty one. Nothing is charged:
                             the guard returns before any Razorpay order. --}}
                        <button type="button" @click="$refs.donationForm.requestSubmit()"
                            class="inline-flex items-center px-4 py-2 rounded-lg text-sm font-semibold bg-amber-600 text-stone-900 hover:bg-amber-500 transition">
                            {{ __('donation.add_pan_now') }}
                        </button>
                        {{-- Only clears the 80G request. It deliberately
                             does NOT tick Gupt Daan: skipping the tax
                             receipt is not a request for anonymity. --}}
                        <button type="button" @click="wants80g = false"
                            class="inline-flex items-center px-4 py-2 rounded-lg text-sm border border-amber-800/40 text-amber-100/60 hover:border-amber-600 transition">
                            {{ __('donation.continue_without_80g') }}
                        </button>
                    </div>
                </div>
            @endunless
        </div>
        @endauth

        {{-- Submit --}}
        @auth('devotee')
            <form method="POST" action="{{ route('donate.create') }}" enctype="multipart/form-data" x-ref="donationForm" data-payment-form>
                @csrf
                <input type="hidden" name="amount" :value="amount">
                <input type="hidden" name="donation_type" :value="donationType">
                <input type="hidden" name="donation_type_id" :value="selectedTypeId || ''">
                <input type="hidden" name="anonymous" :value="anonymous ? 1 : 0">
                <input type="hidden" name="wants_80g" :value="wants80g ? 1 : 0">
                @if($selectedCampaign)
                    <input type="hidden" name="campaign_id" value="{{ $selectedCampaign->id }}">
                    <input type="hidden" name="sub_cause_id" :value="subCauseId || ''">
                @endif

                {{-- Dynamic Extra Fields (inside form so they submit properly) --}}
                <template x-if="currentExtraFields.length > 0">
                    <div class="mb-6 space-y-4 p-4 border border-amber-800/20 rounded-lg bg-amber-900/10">
                        <p class="text-xs text-amber-500 font-medium">{{ __('donation.extra_info') }}</p>
                        <template x-for="(field, index) in currentExtraFields" :key="field.key">
                            <div>
                                <label class="block text-sm font-medium text-amber-600 mb-1" x-text="field.label || field.label_gu || field.label_en"></label>
                                {{-- :value seeds the box from the devotee's profile
                                     (ProfilePrefill) and never fights typing —
                                     field.prefill is static for a given type. --}}
                                <input x-show="field.type === 'text'" type="text"
                                    :name="field.type === 'text' ? 'extra_data[' + field.key + ']' : ''"
                                    :value="field.prefill || ''"
                                    :required="field.type === 'text' && field.required"
                                    class="w-full bg-transparent border-amber-800/30 rounded-lg text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-amber-600/20">
                                <input x-show="field.type === 'number'" type="number"
                                    :name="field.type === 'number' ? 'extra_data[' + field.key + ']' : ''"
                                    :value="field.prefill || ''"
                                    :required="field.type === 'number' && field.required"
                                    class="w-full bg-transparent border-amber-800/30 rounded-lg text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-amber-600/20">
                                <input x-show="field.type === 'date'" type="date"
                                    :name="field.type === 'date' ? 'extra_data[' + field.key + ']' : ''"
                                    :value="field.prefill || ''"
                                    :required="field.type === 'date' && field.required"
                                    class="w-full bg-transparent border-amber-800/30 rounded-lg text-amber-100 focus:border-amber-600 focus:ring-amber-600/20">
                                <input x-show="field.type === 'image'" type="file"
                                    :name="field.type === 'image' ? 'extra_data[' + field.key + ']' : ''"
                                    :required="field.type === 'image' && field.required"
                                    accept="image/*"
                                    class="w-full bg-transparent border-amber-800/30 rounded-lg text-amber-100 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:bg-amber-900/40 file:text-amber-400">
                                <textarea x-show="field.type === 'textarea'"
                                    :name="field.type === 'textarea' ? 'extra_data[' + field.key + ']' : ''"
                                    :value="field.prefill || ''"
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
            <a href="{{ login_url() }}" class="block w-full text-center py-3 btn-divine text-lg">
                {{ __('donation.login_to_donate') }}
            </a>
        @endauth
    </div>

    {{-- Active Campaigns (hidden in single-campaign mode).
         These used to be bare <div>s with no anchor anywhere inside, so the
         cards were dead. Reuse the shared partial the home page and /projects
         already use — it wraps every card in a real <a> to /projects/{slug}. --}}
    @if(!$selectedCampaign && $campaigns->isNotEmpty())
        <div class="mt-10">
            <h2 class="text-xl font-bold text-gold mb-4">{{ __('donation.active_campaigns') }}</h2>
            @include('partials.campaign-grid', ['campaignItems' => $campaigns])
        </div>
    @endif
</div>

@push('scripts')
<script>
function donationForm() {
    // Item 5.4 — form state restored from the query string after the PAN
    // interstitial, so a donor who was sent to their profile to add a PAN
    // comes back to the donation they had already composed instead of an
    // empty form. Falls back to the defaults on a normal first visit.
    const prefill = @json($prefill);

    return {
        amount: prefill.amount || 1100,
        customAmount: String(prefill.amount || 1100),
        selectedTypeId: prefill.donation_type_id || '',
        donationType: @json($selectedCampaign ? 'campaign' : 'general'),
        subCauseId: prefill.sub_cause_id || '',
        anonymous: !!prefill.anonymous,
        wants80g: !!prefill.wants_80g,
        // Campaign mode has no type dropdown, so onTypeChange() never runs
        // and the fields must be seeded from the campaign itself.
        currentExtraFields: @json($campaignExtraFields ?? []),
        campaignExtraFields: @json($campaignExtraFields ?? []),

        // Donation types data from server
        donationTypesData: @json($donationTypesJs),

        // Restoring selectedTypeId alone is not enough: donationType (the
        // slug the API validates) and the dynamic extra_fields are both
        // derived from it, and onTypeChange() is what derives them.
        init() {
            if (this.selectedTypeId) {
                this.onTypeChange();
            }
        },

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

            // A campaign gift keeps the campaign's fields regardless of any
            // type the query string happened to carry.
            if (this.campaignExtraFields.length) {
                this.donationType = 'campaign';
                this.currentExtraFields = this.campaignExtraFields;
            }
        },

        // submitForm no longer needed — extra fields are now inside the form
    };
}
</script>
@endpush
@endsection
