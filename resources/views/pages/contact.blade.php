@extends('layouts.app')

@section('content')

<section class="bg-temple-light border-b border-amber-900/20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        <nav class="flex items-center gap-2 text-sm text-amber-100/30 mb-4">
            <a href="{{ route('home') }}" class="hover:text-gold transition">હોમ</a>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <span class="text-gold font-medium">સ‌ymparak</span>
        </nav>
        <h1 class="divine-heading text-3xl sm:text-4xl">સ‌ymparak કરો</h1>
        <p class="mt-2 divine-subtext">Tamara prashnao ane messages mate samparak karo</p>
    </div>
</section>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 bg-temple">

    {{-- Flash Messages --}}
    @if(session('success'))
        <div class="mb-6 flex items-center gap-3 px-5 py-4 bg-emerald-950/30 border border-emerald-800/30 rounded-xl text-emerald-300">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <p class="text-sm font-medium">{{ session('success') }}</p>
        </div>
    @endif

    @if($errors->any())
        <div class="mb-6 px-5 py-4 bg-red-950/30 border border-red-800/30 rounded-xl">
            <p class="text-sm font-semibold text-red-300 mb-2">Krupaa karI form sudharo:</p>
            <ul class="text-sm text-red-300/80 space-y-1 list-disc list-inside">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-5 gap-8">

        {{-- Contact Form (Left) --}}
        <div class="lg:col-span-3">
            <div class="card-sacred p-6 sm:p-8">
                <h2 class="text-xl font-bold text-gold mb-6">Message moklo</h2>

                <form method="POST" action="{{ route('contact.submit') }}" class="space-y-5">
                    @csrf

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <div>
                            <label for="name" class="block text-sm font-medium text-amber-600 mb-1.5">
                                નામ <span class="text-red-400">*</span>
                            </label>
                            <input type="text" name="name" id="name"
                                   value="{{ old('name') }}"
                                   placeholder="Tamaru naam"
                                   class="w-full px-4 py-2.5 bg-transparent border border-amber-800/30 rounded-lg text-sm text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-1 focus:ring-amber-600/20 @error('name') border-red-700/50 @enderror">
                            @error('name')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label for="phone" class="block text-sm font-medium text-amber-600 mb-1.5">ફોન</label>
                            <input type="tel" name="phone" id="phone"
                                   value="{{ old('phone') }}"
                                   placeholder="+91 XXXXX XXXXX"
                                   class="w-full px-4 py-2.5 bg-transparent border border-amber-800/30 rounded-lg text-sm text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-1 focus:ring-amber-600/20 @error('phone') border-red-700/50 @enderror">
                            @error('phone')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <div>
                        <label for="email" class="block text-sm font-medium text-amber-600 mb-1.5">ઈમેલ</label>
                        <input type="email" name="email" id="email"
                               value="{{ old('email') }}"
                               placeholder="email@example.com"
                               class="w-full px-4 py-2.5 bg-transparent border border-amber-800/30 rounded-lg text-sm text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-1 focus:ring-amber-600/20 @error('email') border-red-700/50 @enderror">
                        @error('email')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="subject" class="block text-sm font-medium text-amber-600 mb-1.5">
                            વિષય <span class="text-red-400">*</span>
                        </label>
                        <input type="text" name="subject" id="subject"
                               value="{{ old('subject') }}"
                               placeholder="Tamaro prashno shaa baabat chhe?"
                               class="w-full px-4 py-2.5 bg-transparent border border-amber-800/30 rounded-lg text-sm text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-1 focus:ring-amber-600/20 @error('subject') border-red-700/50 @enderror">
                        @error('subject')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="message" class="block text-sm font-medium text-amber-600 mb-1.5">
                            સંદેશ <span class="text-red-400">*</span>
                        </label>
                        <textarea name="message" id="message" rows="5"
                                  placeholder="Tamaro prashno / message yahaa lakho..."
                                  class="w-full px-4 py-2.5 bg-transparent border border-amber-800/30 rounded-lg text-sm text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-1 focus:ring-amber-600/20 resize-none @error('message') border-red-700/50 @enderror">{{ old('message') }}</textarea>
                        @error('message')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                    </div>

                    <button type="submit"
                            class="btn-divine w-full sm:w-auto inline-flex items-center justify-center gap-2 px-8 py-3">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                        Message moklo
                    </button>

                </form>
            </div>
        </div>

        {{-- Contact Info (Right) --}}
        <div class="lg:col-span-2 space-y-5">

            <div class="card-sacred p-6">
                <h2 class="text-lg font-bold text-gold mb-4">Samparak Mahiti</h2>
                <div class="space-y-4">

                    @if(isset($trustAddress))
                    <div class="flex items-start gap-3">
                        <div class="flex-shrink-0 w-9 h-9 bg-amber-900/30 rounded-lg flex items-center justify-center">
                            <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        </div>
                        <div>
                            <p class="text-xs text-amber-600 uppercase tracking-wide mb-0.5">સરનામું</p>
                            <p class="text-sm text-amber-100/60 leading-relaxed">{{ $trustAddress }}</p>
                        </div>
                    </div>
                    @endif

                    @if(isset($trustPhone))
                    <div class="flex items-start gap-3">
                        <div class="flex-shrink-0 w-9 h-9 bg-amber-900/30 rounded-lg flex items-center justify-center">
                            <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                        </div>
                        <div>
                            <p class="text-xs text-amber-600 uppercase tracking-wide mb-0.5">ફોન</p>
                            <a href="tel:{{ $trustPhone }}" class="text-sm text-amber-100/60 hover:text-gold transition font-medium">{{ $trustPhone }}</a>
                        </div>
                    </div>
                    @endif

                    @if(isset($trustEmail))
                    <div class="flex items-start gap-3">
                        <div class="flex-shrink-0 w-9 h-9 bg-amber-900/30 rounded-lg flex items-center justify-center">
                            <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        </div>
                        <div>
                            <p class="text-xs text-amber-600 uppercase tracking-wide mb-0.5">ઈમેલ</p>
                            <a href="mailto:{{ $trustEmail }}" class="text-sm text-amber-100/60 hover:text-gold transition font-medium">{{ $trustEmail }}</a>
                        </div>
                    </div>
                    @endif

                </div>
            </div>

            {{-- Google Maps --}}
            <div class="card-sacred overflow-hidden">
                <iframe
                    src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3670.0!2d70.13!3d23.08!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x0!2zMjPCsDA0JzQ4LjAiTiA3MMKwMDcnNDguMCJF!5e0!3m2!1sen!2sin!4v1"
                    width="100%"
                    height="250"
                    style="border:0;"
                    allowfullscreen=""
                    loading="lazy"
                    referrerpolicy="no-referrer-when-downgrade">
                </iframe>
            </div>

        </div>

    </div>

</div>

@endsection
