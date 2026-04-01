@extends('layouts.app')

@section('content')

<section class="bg-temple-light border-b border-amber-900/20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        <nav class="flex items-center gap-2 text-sm text-amber-100/30 mb-4">
            <a href="{{ route('home') }}" class="hover:text-gold transition">મુખ્ય પૃષ્ઠ</a>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <a href="{{ route('dashboard.index') }}" class="hover:text-gold transition">ડેશબોર્ડ</a>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <span class="text-gold font-medium">પ્રોફાઇલ</span>
        </nav>
        <h1 class="divine-heading text-3xl sm:text-4xl">પ્રોફાઇલ</h1>
        <p class="mt-2 divine-subtext">તમારી વ્યક્તિગત માહિતી અપડેટ કરો</p>
    </div>
</section>

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
            <p class="text-sm font-semibold text-red-300 mb-2">કૃપા કરી ફોર્મ સુધારો:</p>
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
                                    <img src="{{ asset('storage/' . $devotee->profile_photo_path) }}" class="w-full h-full object-cover">
                                @else
                                    <div class="w-full h-full flex items-center justify-center" style="background: linear-gradient(145deg, #1a0f08, #0f0804);">
                                        <svg class="w-16 h-16 text-amber-800/40" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                    </div>
                                @endif
                            </template>
                        </div>
                        <label class="absolute bottom-0 right-0 w-9 h-9 rounded-full flex items-center justify-center cursor-pointer border border-amber-800/30 hover:border-amber-600 transition" style="background: linear-gradient(145deg, #1a0f08, #0f0804);">
                            <svg class="w-4 h-4 text-gold" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            <input type="file" name="profile_photo" accept="image/*" class="hidden" @change="preview = URL.createObjectURL($event.target.files[0])">
                        </label>
                    </div>

                    <h3 class="text-gold font-bold text-lg">{{ $devotee->name ?: 'ભક્ત' }}</h3>
                    <p class="text-amber-100/40 text-sm">+91 {{ $devotee->phone }}</p>

                    {{-- Quick Stats --}}
                    <div class="mt-6 space-y-3 text-left">
                        <div class="flex items-center justify-between py-2 border-t border-amber-900/15">
                            <span class="text-amber-100/40 text-xs">ફોન ચકાસણી</span>
                            <span class="text-xs {{ $devotee->phone_verified_at ? 'text-emerald-400' : 'text-amber-400' }}">
                                {{ $devotee->phone_verified_at ? '✓ ચકાસેલ' : 'બાકી' }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between py-2 border-t border-amber-900/15">
                            <span class="text-amber-100/40 text-xs">PAN</span>
                            <span class="text-xs {{ $devotee->pan_encrypted ? 'text-emerald-400' : 'text-amber-100/30' }}">
                                {{ $devotee->pan_encrypted ? '✓ ******' . $devotee->pan_last_four : 'ઉમેરો' }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between py-2 border-t border-amber-900/15">
                            <span class="text-amber-100/40 text-xs">ભાષા</span>
                            <span class="text-xs text-amber-100/60">{{ ['gu' => 'ગુજરાતી', 'hi' => 'हिन्दी', 'en' => 'English'][$devotee->language?->value ?? 'gu'] ?? 'ગુજરાતી' }}</span>
                        </div>
                        <div class="flex items-center justify-between py-2 border-t border-amber-900/15">
                            <span class="text-amber-100/40 text-xs">રજિસ્ટર થયા</span>
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
                        વ્યક્તિગત માહિતી
                    </h2>

                    <div class="space-y-5">
                        {{-- Name + Email --}}
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <div>
                                <label for="name" class="block text-sm font-medium text-amber-600 mb-1.5">નામ <span class="text-red-400">*</span></label>
                                <input type="text" name="name" id="name" value="{{ old('name', $devotee->name ?? '') }}"
                                    class="w-full px-4 py-2.5 bg-transparent border border-amber-800/30 rounded-lg text-sm text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-1 focus:ring-amber-600/20">
                                @error('name')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="email" class="block text-sm font-medium text-amber-600 mb-1.5">ઈમેલ</label>
                                <input type="email" name="email" id="email" value="{{ old('email', $devotee->email ?? '') }}"
                                    placeholder="example@email.com"
                                    class="w-full px-4 py-2.5 bg-transparent border border-amber-800/30 rounded-lg text-sm text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-1 focus:ring-amber-600/20">
                                @error('email')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                            </div>
                        </div>

                        {{-- City + State --}}
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <div>
                                <label for="city" class="block text-sm font-medium text-amber-600 mb-1.5">શહેર</label>
                                <input type="text" name="city" id="city" value="{{ old('city', $devotee->city ?? '') }}"
                                    placeholder="તમારું શહેર"
                                    class="w-full px-4 py-2.5 bg-transparent border border-amber-800/30 rounded-lg text-sm text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-1 focus:ring-amber-600/20">
                            </div>
                            <div>
                                <label for="state" class="block text-sm font-medium text-amber-600 mb-1.5">રાજ્ય</label>
                                <input type="text" name="state" id="state" value="{{ old('state', $devotee->state ?? '') }}"
                                    placeholder="તમારું રાજ્ય"
                                    class="w-full px-4 py-2.5 bg-transparent border border-amber-800/30 rounded-lg text-sm text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-1 focus:ring-amber-600/20">
                            </div>
                        </div>

                        {{-- Pincode + DOB --}}
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <div>
                                <label for="pincode" class="block text-sm font-medium text-amber-600 mb-1.5">પિનકોડ</label>
                                <input type="text" name="pincode" id="pincode" value="{{ old('pincode', $devotee->pincode ?? '') }}"
                                    placeholder="370205" maxlength="6"
                                    class="w-full px-4 py-2.5 bg-transparent border border-amber-800/30 rounded-lg text-sm text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-1 focus:ring-amber-600/20">
                            </div>
                            <div>
                                <label for="date_of_birth" class="block text-sm font-medium text-amber-600 mb-1.5">જન્મ તારીખ</label>
                                <input type="date" name="date_of_birth" id="date_of_birth"
                                    value="{{ old('date_of_birth', optional($devotee->date_of_birth ?? null)?->format('Y-m-d')) }}"
                                    class="w-full px-4 py-2.5 bg-transparent border border-amber-800/30 rounded-lg text-sm text-amber-100 focus:border-amber-600 focus:ring-1 focus:ring-amber-600/20">
                            </div>
                        </div>

                        {{-- Language + PAN --}}
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <div>
                                <label for="language" class="block text-sm font-medium text-amber-600 mb-1.5">પ્રાધાન્ય ભાષા</label>
                                <select name="language" id="language"
                                    class="w-full px-4 py-2.5 bg-transparent border border-amber-800/30 rounded-lg text-sm text-amber-100 focus:border-amber-600 focus:ring-1 focus:ring-amber-600/20">
                                    <option value="gu" class="bg-stone-900" {{ old('language', $devotee->language?->value ?? 'gu') === 'gu' ? 'selected' : '' }}>ગુજરાતી</option>
                                    <option value="hi" class="bg-stone-900" {{ old('language', $devotee->language?->value ?? '') === 'hi' ? 'selected' : '' }}>हिन्दी</option>
                                    <option value="en" class="bg-stone-900" {{ old('language', $devotee->language?->value ?? '') === 'en' ? 'selected' : '' }}>English</option>
                                </select>
                            </div>
                            <div>
                                <label for="pan_number" class="block text-sm font-medium text-amber-600 mb-1.5">
                                    PAN નંબર
                                    <span class="text-xs text-amber-100/30 font-normal">(80G માટે)</span>
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
                                પ્રોફાઇલ સાચવો
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

@endsection
