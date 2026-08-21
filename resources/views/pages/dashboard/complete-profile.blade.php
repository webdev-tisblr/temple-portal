@extends('layouts.app')

@section('content')
{{-- Onboarding gate, not a dashboard page: no section nav, no page-header
     band — the devotee has just verified an OTP and is being asked for the
     minimum needed to book or donate. Re-skinned to the parchment palette
     so it no longer depends on the app.css compatibility layer. --}}
<div class="max-w-xl mx-auto px-4 sm:px-6 lg:px-8 py-12 bg-temple">

    <div class="mb-8 text-center">
        <img src="{{ asset('images/shree-pataliya-hanumanji-logo.png') }}"
             alt="{{ __('common.temple_name') }}"
             class="diya-glow mx-auto mb-4 h-16 w-16 rounded-full"
             style="border: 2px solid rgba(200,148,52,0.45); box-shadow: 0 0 25px rgba(196,154,42,0.3);">
        <h1 class="text-2xl font-semibold"
            style="font-family: 'Marcellus', 'Noto Serif Gujarati', 'Noto Serif Devanagari', serif; color: #7A1E1E;">
            {{ __('dashboard.complete_profile') }}
        </h1>
        <p class="mt-1.5 text-sm" style="color: #5E4F3D;">{{ __('dashboard.complete_profile_sub') }}</p>
    </div>

    @if($errors->any())
        <div class="dash-notice dash-notice-bad mb-6">
            <x-dashboard.icon path="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" class="w-5 h-5 shrink-0 mt-0.5" />
            <ul class="list-inside list-disc space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="dash-panel p-6 sm:p-8">
        <form method="POST" action="{{ route('profile.complete.save') }}" class="space-y-5">
            @csrf

            {{-- Phone (read-only) --}}
            <div>
                <label class="dash-label">{{ __('halls.phone_number') }}</label>
                <div class="flex items-center gap-2 rounded-lg px-4 py-2.5"
                     style="background: rgba(232,117,26,0.08); border: 1px solid rgba(122,30,30,0.12); color: #3E3226;">
                    <span>{{ \App\Support\PhoneNumber::forDisplay($devotee->phone) }}</span>
                    <span class="ml-auto" style="color: #2D5F2D;">
                        <x-dashboard.icon path="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" class="w-4 h-4" />
                    </span>
                </div>
            </div>

            {{-- Name (required) --}}
            <div>
                <label for="cp_name" class="dash-label">
                    {{ __('dashboard.full_name') }} <span style="color: #A83232;">*</span>
                </label>
                <input type="text" name="name" id="cp_name" value="{{ old('name') }}" required autofocus
                       placeholder="{{ __('dashboard.full_name_placeholder') }}" class="dash-input">
            </div>

            {{-- Only the NAME above is compulsory (2026-08-21). Everything
                 below is saved when filled and skipped when not: the name is
                 what receipts and WhatsApp templates bind, the rest is
                 collected by the flows that actually need it (checkout asks
                 for a delivery address). A long interstitial is what made
                 people abandon this form, and an abandoned form is how the
                 nameless accounts happened in the first place. --}}

            {{-- Email — accepted blank, but deliberately NOT marked optional:
                 many devotees here have no email at all, and labelling it
                 optional invites everyone else to skip it too. No asterisk
                 either, since that would claim it is enforced. --}}
            <div>
                <label for="cp_email" class="dash-label">{{ __('common.email') }}</label>
                <input type="email" name="email" id="cp_email" value="{{ old('email') }}"
                       placeholder="example@email.com" class="dash-input">
            </div>

            {{-- Address --}}
            <div>
                <label for="cp_address" class="dash-label">{{ __('store.address') }}</label>
                <input type="text" name="address" id="cp_address" value="{{ old('address') }}"
                       placeholder="{{ __('dashboard.address_placeholder') }}" class="dash-input">
            </div>

            {{-- City & State --}}
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div>
                    <label for="cp_city" class="dash-label">{{ __('store.city') }}</label>
                    <input type="text" name="city" id="cp_city" value="{{ old('city') }}"
                           placeholder="{{ __('dashboard.city_placeholder_ex') }}" class="dash-input">
                </div>
                <div>
                    <label for="cp_state" class="dash-label">{{ __('store.state') }}</label>
                    <input type="text" name="state" id="cp_state" value="{{ old('state', 'Gujarat') }}" class="dash-input">
                </div>
            </div>

            {{-- Pincode — blank is fine, but a WRONG one is worse than
                 none (it breaks prasad despatch silently), so the pattern
                 still applies to anything typed. --}}
            <div>
                <label for="cp_pincode" class="dash-label">{{ __('store.pincode') }}</label>
                <input type="text" name="pincode" id="cp_pincode" value="{{ old('pincode') }}" maxlength="6"
                       inputmode="numeric" pattern="[1-9][0-9]{5}" title="{{ __('dashboard.pincode_invalid') }}"
                       placeholder="370201" class="dash-input">
            </div>

            {{-- PAN (optional, for 80G) --}}
            <div>
                <label for="cp_pan" class="dash-label">
                    {{ __('dashboard.pan_number') }}
                    <span class="text-xs font-normal" style="color: #8A7860;">{{ __('dashboard.pan_for_80g_optional') }}</span>
                </label>
                <input type="text" name="pan_number" id="cp_pan" value="{{ old('pan_number') }}" maxlength="10"
                       placeholder="ABCDE1234F" class="dash-input font-mono uppercase">
                {{-- PAN is optional at registration (item 5.4) — the
                     disclaimer states what leaving it blank means, rather
                     than the form simply going quiet about it. --}}
                <p class="dash-hint">{{ __('dashboard.pan_disclaimer') }}</p>
            </div>

            <button type="submit" class="btn-divine w-full">
                {{ __('dashboard.save_profile2') }}
            </button>
        </form>
    </div>
</div>
@endsection
