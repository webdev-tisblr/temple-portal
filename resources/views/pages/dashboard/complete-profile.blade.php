@extends('layouts.app')

@section('content')
<div class="max-w-xl mx-auto px-4 sm:px-6 lg:px-8 py-12 bg-temple">

    <div class="text-center mb-8">
        <img src="{{ asset('images/shree-pataliya-hanumanji-logo.png') }}" alt="{{ __('common.temple_name') }}" class="w-16 h-16 rounded-full mx-auto mb-4 border-2 border-amber-600/40 diya-glow" style="box-shadow: 0 0 25px rgba(196,154,42,0.3);">
        <h1 class="text-2xl font-black text-gold">{{ __('dashboard.complete_profile') }}</h1>
        <p class="text-amber-200/60 mt-1 text-sm">{{ __('dashboard.complete_profile_sub') }}</p>
    </div>

    @if($errors->any())
        <div class="bg-red-950/30 border border-red-800/30 text-red-300 px-4 py-3 rounded-lg mb-6 text-sm">
            @foreach($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <div class="card-sacred p-6 sm:p-8">
        <form method="POST" action="{{ route('profile.complete.save') }}">
            @csrf

            {{-- Phone (read-only) --}}
            <div class="mb-5">
                <label class="block text-sm font-medium text-amber-600 mb-1">{{ __('halls.phone_number') }}</label>
                <div class="flex items-center gap-2 text-amber-100/60 bg-amber-900/20 border border-amber-800/20 rounded-lg px-4 py-2.5">
                    <span>{{ \App\Support\PhoneNumber::forDisplay($devotee->phone) }}</span>
                    <svg class="w-4 h-4 text-green-400 ml-auto" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                </div>
            </div>

            {{-- Name (required) --}}
            <div class="mb-5">
                <label class="block text-sm font-medium text-amber-600 mb-1">{{ __('dashboard.full_name') }} <span class="text-red-400">*</span></label>
                <input type="text" name="name" value="{{ old('name') }}" required autofocus
                    placeholder="{{ __('dashboard.full_name_placeholder') }}"
                    class="w-full bg-transparent border-amber-800/30 rounded-lg text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-amber-600/20">
            </div>

            {{-- Email (optional) --}}
            <div class="mb-5">
                <label class="block text-sm font-medium text-amber-600 mb-1">{{ __('common.email') }} <span class="text-amber-100/30 text-xs">{{ __('store.optional') }}</span></label>
                <input type="email" name="email" value="{{ old('email') }}"
                    placeholder="example@email.com"
                    class="w-full bg-transparent border-amber-800/30 rounded-lg text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-amber-600/20">
            </div>

            {{-- Address --}}
            <div class="mb-5">
                <label class="block text-sm font-medium text-amber-600 mb-1">{{ __('store.address') }} <span class="text-amber-100/30 text-xs">{{ __('store.optional') }}</span></label>
                <input type="text" name="address" value="{{ old('address') }}"
                    placeholder="{{ __('dashboard.address_placeholder') }}"
                    class="w-full bg-transparent border-amber-800/30 rounded-lg text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-amber-600/20">
            </div>

            {{-- City & State --}}
            <div class="grid grid-cols-2 gap-4 mb-5">
                <div>
                    <label class="block text-sm font-medium text-amber-600 mb-1">{{ __('store.city') }}</label>
                    <input type="text" name="city" value="{{ old('city') }}"
                        placeholder="{{ __('dashboard.city_placeholder_ex') }}"
                        class="w-full bg-transparent border-amber-800/30 rounded-lg text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-amber-600/20">
                </div>
                <div>
                    <label class="block text-sm font-medium text-amber-600 mb-1">{{ __('store.state') }}</label>
                    <input type="text" name="state" value="{{ old('state', 'Gujarat') }}"
                        class="w-full bg-transparent border-amber-800/30 rounded-lg text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-amber-600/20">
                </div>
            </div>

            {{-- Pincode --}}
            <div class="mb-5">
                <label class="block text-sm font-medium text-amber-600 mb-1">{{ __('store.pincode') }}</label>
                <input type="text" name="pincode" value="{{ old('pincode') }}" maxlength="6"
                    placeholder="370201"
                    class="w-full bg-transparent border-amber-800/30 rounded-lg text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-amber-600/20">
            </div>

            {{-- PAN (optional, for 80G) --}}
            <div class="mb-6">
                <label class="block text-sm font-medium text-amber-600 mb-1">{{ __('dashboard.pan_number') }} <span class="text-amber-100/30 text-xs">{{ __('dashboard.pan_for_80g_optional') }}</span></label>
                <input type="text" name="pan_number" value="{{ old('pan_number') }}" maxlength="10"
                    placeholder="ABCDE1234F" style="text-transform: uppercase;"
                    class="w-full bg-transparent border-amber-800/30 rounded-lg text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-amber-600/20">
                <p class="text-xs text-amber-100/30 mt-1">{{ __('dashboard.pan_hint') }}</p>
            </div>

            <button type="submit" class="w-full py-3 btn-divine text-lg font-semibold">
                {{ __('dashboard.save_profile2') }}
            </button>
        </form>
    </div>
</div>
@endsection
