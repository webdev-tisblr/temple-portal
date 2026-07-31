@extends('layouts.app')

@section('content')

<x-page-header
    :breadcrumb="[
        ['label' => __('nav.dashboard'), 'url' => route('dashboard.index')],
        ['label' => __('dashboard.profile')],
    ]"
    title="{{ __('dashboard.profile') }}"
    subtitle="{{ __('dashboard.update_profile_sub') }}" />

<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-10 bg-temple">

    {{-- Flash Messages --}}
    @if(session('success'))
        <div class="mb-6 flex items-center gap-3 px-5 py-4 bg-emerald-950/30 border border-emerald-800/30 rounded-xl text-emerald-300">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <p class="text-sm font-medium">{{ session('success') }}</p>
        </div>
    @endif

    @if($errors->any())
        <div class="mb-6 px-5 py-4 bg-red-950/30 border border-red-800/30 rounded-xl">
            <p class="text-sm font-semibold text-red-300 mb-2">{{ __('store.form_errors') }}</p>
            <ul class="text-sm text-red-300/80 space-y-1 list-disc list-inside">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('dashboard.profile.update') }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')

        <div class="flex flex-col lg:flex-row gap-8">

            {{-- Left: Profile Photo --}}
            <div class="lg:w-72 flex-shrink-0">
                <div class="card-sacred p-6 text-center inner-glow sticky top-24">
                    {{-- Photo --}}
                    <div class="relative inline-block mb-4" x-data="{ preview: null }">
                        <div class="w-32 h-32 rounded-full mx-auto overflow-hidden border-2 border-amber-800/30" style="box-shadow: 0 0 20px rgba(196,154,42,0.15);">
                            <template x-if="preview">
                                <img :src="preview" class="w-full h-full object-cover">
                            </template>
                            <template x-if="!preview">
                                @if($devotee->profile_photo_path)
                                    <img src="{{ image_url($devotee->profile_photo_path) }}" class="w-full h-full object-cover">
                                @else
                                    <div class="w-full h-full flex items-center justify-center" style="background: linear-gradient(145deg, #FFFCF5, #F4EAD5);">
                                        <svg class="w-16 h-16" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: #C89434;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                    </div>
                                @endif
                            </template>
                        </div>
                        <label class="absolute bottom-0 right-0 w-9 h-9 rounded-full flex items-center justify-center cursor-pointer border hover:border-[#E8751A] transition shadow-md" style="background: #FFFCF5; border-color: rgba(200,148,52,0.45);">
                            <svg class="w-4 h-4 text-gold" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            <input type="file" name="profile_photo" accept="image/*" class="hidden" @change="preview = URL.createObjectURL($event.target.files[0])">
                        </label>
                    </div>

                    <h3 class="text-gold font-bold text-lg">{{ $devotee->name ?: __('common.devotee') }}</h3>
                    <p class="text-amber-100/40 text-sm">{{ \App\Support\PhoneNumber::forDisplay($devotee->phone) }}</p>

                    {{-- Quick Stats --}}
                    <div class="mt-6 space-y-3 text-left">
                        <div class="flex items-center justify-between py-2 border-t border-amber-900/15">
                            <span class="text-amber-100/40 text-xs">{{ __('dashboard.phone_verify') }}</span>
                            <span class="text-xs {{ $devotee->phone_verified_at ? 'text-emerald-400' : 'text-amber-400' }}">
                                {{ $devotee->phone_verified_at ? __('dashboard.verified') : __('dashboard.pending_status') }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between py-2 border-t border-amber-900/15">
                            <span class="text-amber-100/40 text-xs">PAN</span>
                            <span class="text-xs {{ $devotee->pan_encrypted ? 'text-emerald-400' : 'text-amber-100/30' }}">
                                {{ $devotee->pan_encrypted ? '✓ ******' . $devotee->pan_last_four : __('dashboard.add') }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between py-2 border-t border-amber-900/15">
                            <span class="text-amber-100/40 text-xs">{{ __('dashboard.language') }}</span>
                            <span class="text-xs text-amber-100/60">{{ ['gu' => 'ગુજરાતી', 'hi' => 'हिन्दी', 'en' => 'English'][$devotee->language?->value ?? 'gu'] ?? 'ગુજરાતી' }}</span>
                        </div>
                        <div class="flex items-center justify-between py-2 border-t border-amber-900/15">
                            <span class="text-amber-100/40 text-xs">{{ __('dashboard.registered') }}</span>
                            <span class="text-xs text-amber-100/60">{{ $devotee->created_at?->format('d M Y') }}</span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Right: Form Fields --}}
            <div class="flex-1">
                <div class="card-sacred p-6 sm:p-8 inner-glow">
                    <h2 class="text-gold font-bold text-lg mb-6 flex items-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        {{ __('dashboard.personal_info') }}
                    </h2>

                    <div class="space-y-5">
                        {{-- Name + Email --}}
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <div>
                                <label for="name" class="block text-sm font-medium text-amber-600 mb-1.5">{{ __('store.name') }} <span class="text-red-400">*</span></label>
                                <input type="text" name="name" id="name" value="{{ old('name', $devotee->name ?? '') }}"
                                    class="w-full px-4 py-2.5 bg-transparent border border-amber-800/30 rounded-lg text-sm text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-1 focus:ring-amber-600/20">
                                @error('name')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="email" class="block text-sm font-medium text-amber-600 mb-1.5">{{ __('common.email') }}</label>
                                <input type="email" name="email" id="email" value="{{ old('email', $devotee->email ?? '') }}"
                                    placeholder="example@email.com"
                                    class="w-full px-4 py-2.5 bg-transparent border border-amber-800/30 rounded-lg text-sm text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-1 focus:ring-amber-600/20">
                                @error('email')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                            </div>
                        </div>

                        {{-- City + State --}}
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <div>
                                <label for="city" class="block text-sm font-medium text-amber-600 mb-1.5">{{ __('store.city') }}</label>
                                <input type="text" name="city" id="city" value="{{ old('city', $devotee->city ?? '') }}"
                                    placeholder="{{ __('store.city_placeholder') }}"
                                    class="w-full px-4 py-2.5 bg-transparent border border-amber-800/30 rounded-lg text-sm text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-1 focus:ring-amber-600/20">
                            </div>
                            <div>
                                <label for="state" class="block text-sm font-medium text-amber-600 mb-1.5">{{ __('store.state') }}</label>
                                <input type="text" name="state" id="state" value="{{ old('state', $devotee->state ?? '') }}"
                                    placeholder="{{ __('store.state_placeholder') }}"
                                    class="w-full px-4 py-2.5 bg-transparent border border-amber-800/30 rounded-lg text-sm text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-1 focus:ring-amber-600/20">
                            </div>
                        </div>

                        {{-- Pincode + DOB --}}
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <div>
                                <label for="pincode" class="block text-sm font-medium text-amber-600 mb-1.5">{{ __('store.pincode') }}</label>
                                <input type="text" name="pincode" id="pincode" value="{{ old('pincode', $devotee->pincode ?? '') }}"
                                    placeholder="370205" maxlength="6"
                                    class="w-full px-4 py-2.5 bg-transparent border border-amber-800/30 rounded-lg text-sm text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-1 focus:ring-amber-600/20">
                            </div>
                            <div>
                                <label for="date_of_birth" class="block text-sm font-medium text-amber-600 mb-1.5">{{ __('dashboard.dob') }}</label>
                                <input type="date" name="date_of_birth" id="date_of_birth"
                                    value="{{ old('date_of_birth', optional($devotee->date_of_birth ?? null)?->format('Y-m-d')) }}"
                                    class="w-full px-4 py-2.5 bg-transparent border border-amber-800/30 rounded-lg text-sm text-amber-100 focus:border-amber-600 focus:ring-1 focus:ring-amber-600/20">
                            </div>
                        </div>

                        {{-- PAN (language preference moved to the site header switcher,
                             which now saves to the devotee profile) --}}
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <div>
                                <label for="pan_number" class="block text-sm font-medium text-amber-600 mb-1.5">
                                    {{ __('dashboard.pan_number') }}
                                    <span class="text-xs text-amber-100/30 font-normal">{{ __('dashboard.for_80g') }}</span>
                                </label>
                                <input type="text" name="pan_number" id="pan_number" value="{{ old('pan_number') }}"
                                    placeholder="{{ $devotee->pan_last_four ? '******' . $devotee->pan_last_four : 'ABCDE1234F' }}"
                                    maxlength="10"
                                    class="w-full px-4 py-2.5 bg-transparent border border-amber-800/30 rounded-lg text-sm font-mono uppercase text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-1 focus:ring-amber-600/20">
                                @error('pan_number')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                            </div>
                        </div>

                        {{-- Submit --}}
                        <div class="pt-4 border-t border-amber-900/15">
                            <button type="submit" class="btn-divine inline-flex items-center gap-2 px-8 py-3">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                {{ __('dashboard.save_profile') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

@endsection
