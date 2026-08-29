@extends('layouts.app')

@section('content')

<x-page-header
    :breadcrumb="[
        ['label' => __('nav.dashboard'), 'url' => route('dashboard.index')],
        ['label' => __('dashboard.profile')],
    ]"
    title="{{ __('dashboard.profile') }}"
    subtitle="{{ __('dashboard.update_profile_sub') }}" />

<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-10 bg-temple">

    <x-dashboard.nav active="profile" />

    {{-- Flash Messages --}}
    @if(session('success'))
        <div class="dash-notice dash-notice-ok mb-6">
            <x-dashboard.icon path="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" class="w-5 h-5 shrink-0 mt-0.5" />
            <p class="font-medium">{{ session('success') }}</p>
        </div>
    @endif

    {{-- Item 5.4 — the donor arrived here from the donate form because they
         asked for an 80G receipt without a PAN on file. Saving the profile
         takes them straight back to that donation (SafeRedirect, the same
         return-destination mechanism as post-login redirect). --}}
    @if(session('pan_required_for_80g'))
        <div class="dash-notice dash-notice-warn mb-6">
            <x-dashboard.icon path="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" class="w-5 h-5 shrink-0 mt-0.5" />
            <div>
                <p class="font-semibold">{{ __('donation.pan_required_title') }}</p>
                <p class="mt-1">{{ __('dashboard.pan_needed_banner') }}</p>
            </div>
        </div>
    @endif

    @if($errors->any())
        <div class="dash-notice dash-notice-bad mb-6">
            <x-dashboard.icon path="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" class="w-5 h-5 shrink-0 mt-0.5" />
            <div>
                <p class="font-semibold">{{ __('store.form_errors') }}</p>
                <ul class="mt-1.5 list-inside list-disc space-y-1">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    {{-- Multipart PUT: `profile_photo` is stored on the r2 disk by
         DashboardController::updateProfile. Do not rename the input or
         drop the enctype. --}}
    <form method="POST" action="{{ route('dashboard.profile.update') }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')

        <div class="flex flex-col gap-6 lg:flex-row">

            {{-- Left: photo + at-a-glance facts --}}
            <div class="lg:w-72 lg:flex-shrink-0">
                <div class="dash-panel sticky top-24 p-6 text-center">
                    <div class="relative mb-4 inline-block" x-data="{ preview: null }">
                        <div class="mx-auto h-32 w-32 overflow-hidden rounded-full"
                             style="border: 2px solid rgba(200,148,52,0.45); box-shadow: 0 0 20px rgba(196,154,42,0.15);">
                            <template x-if="preview">
                                <img :src="preview" alt="" class="h-full w-full object-cover">
                            </template>
                            <template x-if="!preview">
                                @if($devotee->profile_photo_path)
                                    <img src="{{ image_url($devotee->profile_photo_path) }}" alt="" class="h-full w-full object-cover">
                                @else
                                    <div class="flex h-full w-full items-center justify-center"
                                         style="background: linear-gradient(145deg, #FFFCF5, #F4EAD5); color: #C89434;">
                                        <x-dashboard.icon path="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" class="w-16 h-16" />
                                    </div>
                                @endif
                            </template>
                        </div>

                        <label class="absolute bottom-0 right-0 flex h-9 w-9 cursor-pointer items-center justify-center rounded-full shadow-md transition"
                               style="background: #FFFCF5; border: 1px solid rgba(200,148,52,0.45); color: #C45F12;"
                               title="{{ __('nav.add_profile_photo') }}">
                            <x-dashboard.icon path="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" class="w-4 h-4" />
                            <span class="sr-only">{{ __('nav.add_profile_photo') }}</span>
                            {{-- data-crop opens the 1:1 cropper before this
                                 ever reaches the server; the handler below
                                 re-runs on the change it dispatches, so the
                                 preview shows the CROPPED photo. --}}
                            <input type="file" name="profile_photo" accept="image/*" class="hidden" data-crop
                                   @change="$event.target.files[0] && (preview = URL.createObjectURL($event.target.files[0]))">
                        </label>
                    </div>

                    <h2 class="text-lg font-semibold"
                        style="font-family: 'Marcellus', 'Noto Serif Gujarati', 'Noto Serif Devanagari', serif; color: #7A1E1E;">
                        {{ $devotee->name ?: __('common.devotee') }}
                    </h2>
                    <p class="text-sm" style="color: #8A7860;">{{ \App\Support\PhoneNumber::forDisplay($devotee->phone) }}</p>

                    <dl class="mt-6 space-y-0 text-left">
                        <div class="flex items-center justify-between gap-3 py-2.5" style="border-top: 1px solid rgba(122,30,30,0.10);">
                            <dt class="text-xs" style="color: #8A7860;">{{ __('dashboard.phone_verify') }}</dt>
                            <dd class="text-xs font-semibold" style="color: {{ $devotee->phone_verified_at ? '#2D5F2D' : '#C45F12' }};">
                                {{ $devotee->phone_verified_at ? __('dashboard.verified') : __('dashboard.pending_status') }}
                            </dd>
                        </div>
                        <div class="flex items-center justify-between gap-3 py-2.5" style="border-top: 1px solid rgba(122,30,30,0.10);">
                            <dt class="text-xs" style="color: #8A7860;">{{ __('dashboard.pan_number') }}</dt>
                            <dd class="text-xs font-semibold" style="color: {{ $devotee->pan_encrypted ? '#2D5F2D' : '#8A7860' }};">
                                {{ $devotee->pan_encrypted ? '✓ ******'.$devotee->pan_last_four : __('dashboard.add') }}
                            </dd>
                        </div>
                        <div class="flex items-center justify-between gap-3 py-2.5" style="border-top: 1px solid rgba(122,30,30,0.10);">
                            <dt class="text-xs" style="color: #8A7860;">{{ __('dashboard.language') }}</dt>
                            <dd class="text-xs" style="color: #3E3226;">{{ ['gu' => 'ગુજરાતી', 'hi' => 'हिन्दी', 'en' => 'English'][$devotee->language?->value ?? 'gu'] ?? 'ગુજરાતી' }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3 py-2.5" style="border-top: 1px solid rgba(122,30,30,0.10);">
                            <dt class="text-xs" style="color: #8A7860;">{{ __('dashboard.registered') }}</dt>
                            <dd class="text-xs" style="color: #3E3226;">{{ $devotee->created_at?->format('d/m/Y') }}</dd>
                        </div>
                    </dl>
                </div>
            </div>

            {{-- Right: the editable fields --}}
            <div class="flex-1">
                <x-dashboard.panel
                    :title="__('dashboard.personal_info')"
                    icon="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z">

                    <div class="space-y-5 p-6 sm:p-8">
                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <div>
                                <label for="name" class="dash-label">
                                    {{ __('store.name') }} <span style="color: #A83232;">*</span>
                                </label>
                                <input type="text" name="name" id="name" value="{{ old('name', $devotee->name ?? '') }}" class="dash-input">
                                @error('name')<p class="dash-error">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="email" class="dash-label">{{ __('common.email') }}</label>
                                <input type="email" name="email" id="email" value="{{ old('email', $devotee->email ?? '') }}"
                                       placeholder="example@email.com" class="dash-input">
                                @error('email')<p class="dash-error">{{ $message }}</p>@enderror
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <div>
                                <label for="city" class="dash-label">{{ __('store.city') }}</label>
                                <input type="text" name="city" id="city" value="{{ old('city', $devotee->city ?? '') }}"
                                       placeholder="{{ __('store.city_placeholder') }}" class="dash-input">
                            </div>
                            <div>
                                <label for="state" class="dash-label">{{ __('store.state') }}</label>
                                <input type="text" name="state" id="state" value="{{ old('state', $devotee->state ?? '') }}"
                                       placeholder="{{ __('store.state_placeholder') }}" class="dash-input">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <div>
                                <label for="pincode" class="dash-label">{{ __('store.pincode') }}</label>
                                <input type="text" name="pincode" id="pincode" value="{{ old('pincode', $devotee->pincode ?? '') }}"
                                       placeholder="370205" maxlength="6" class="dash-input">
                            </div>
                            <div>
                                <label for="date_of_birth" class="dash-label">{{ __('dashboard.dob') }}</label>
                                <input type="date" name="date_of_birth" id="date_of_birth"
                                       value="{{ old('date_of_birth', optional($devotee->date_of_birth ?? null)?->format('Y-m-d')) }}"
                                       class="dash-input">
                            </div>
                        </div>

                        {{-- PAN (language preference moved to the site header switcher,
                             which now saves to the devotee profile) --}}
                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <div>
                                <label for="pan_number" class="dash-label">
                                    {{ __('dashboard.pan_number') }}
                                    <span class="text-xs font-normal" style="color: #8A7860;">{{ __('dashboard.for_80g') }}</span>
                                </label>
                                <input type="text" name="pan_number" id="pan_number" value="{{ old('pan_number') }}"
                                       placeholder="{{ $devotee->pan_last_four ? '******'.$devotee->pan_last_four : 'ABCDE1234F' }}"
                                       maxlength="10" class="dash-input font-mono uppercase">
                                @error('pan_number')<p class="dash-error">{{ $message }}</p>@enderror

                                {{-- The disclaimer the 5.4 brief asks for: PAN
                                     stays optional, and this says plainly what
                                     leaving it blank costs. --}}
                                <p class="dash-hint">{{ __('dashboard.pan_disclaimer') }}</p>

                                {{-- Removing a saved PAN — previously impossible
                                     on every surface (web, app and API all
                                     guarded on `! empty($pan_number)`), so a
                                     devotee who typed a WRONG PAN was stuck
                                     with it on their statutory receipts. The
                                     field submits blank whenever it isn't being
                                     changed, so an explicit checkbox is the only
                                     signal that can't wipe a PAN by accident. --}}
                                @if($devotee->pan_encrypted)
                                    <label class="mt-3 flex items-start gap-2">
                                        <input type="checkbox" name="clear_pan" value="1" class="mt-0.5 rounded">
                                        <span>
                                            <span class="text-sm" style="color: #3E3226;">{{ __('dashboard.clear_pan') }}</span>
                                            <span class="block text-xs" style="color: #8A7860;">{{ __('dashboard.clear_pan_hint') }}</span>
                                        </span>
                                    </label>
                                @endif
                            </div>
                        </div>

                        <div class="pt-4" style="border-top: 1px solid rgba(122,30,30,0.10);">
                            <button type="submit" class="btn-divine">
                                <x-dashboard.icon path="M5 13l4 4L19 7" class="w-4 h-4" />
                                {{ __('dashboard.save_profile') }}
                            </button>
                        </div>
                    </div>
                </x-dashboard.panel>
            </div>
        </div>
    </form>
</div>

@endsection
