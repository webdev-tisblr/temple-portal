{{-- Community Hall band (design import). Expects $hall (nullable). --}}
@if(isset($hall) && $hall)
    <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="grid md:grid-cols-2 rounded-3xl overflow-hidden" style="background:#7A1E1E; color:#F7EAD0;">
            <div class="p-8 sm:p-12">
                <p class="text-[11px] tracking-[0.24em] font-bold" style="color:#E0A35C;">{{ __('home.community_hall') }}</p>
                <h2 class="font-marcellus text-2xl sm:text-3xl mt-3 leading-snug">{{ $hall->name }}</h2>
                @if($hall->description)
                    <p class="text-sm opacity-80 mt-3 leading-relaxed">{{ text_preview($hall->description, 160) }}</p>
                @endif
                <div class="flex flex-wrap gap-8 mt-7 text-sm">
                    @if($hall->capacity)
                        <div><div class="font-marcellus text-2xl" style="color:#F3DCAE;">{{ $hall->capacity }}</div><div class="opacity-70">{{ __('home.guest_capacity') }}</div></div>
                    @endif
                    @if($hall->price_per_day)
                        <div><div class="font-marcellus text-2xl" style="color:#F3DCAE;">₹{{ number_format((float) $hall->price_per_day) }}</div><div class="opacity-70">{{ __('home.per_day') }}</div></div>
                    @endif
                </div>
                <a href="{{ route('halls.show', $hall) }}"
                   class="inline-block mt-8 px-7 py-3 rounded-full text-sm font-extrabold transition hover:opacity-90"
                   style="background:#E8751A; color:#FFF7EC;">{{ __('home.check_availability') }}</a>
            </div>
            <div class="min-h-[240px] relative"
                 style="background: repeating-linear-gradient(45deg,#571410 0 14px,#642018 14px 28px);">
                @if($hall->image_path)
                    <img src="{{ image_url($hall->image_path) }}" alt="{{ $hall->name }}" class="absolute inset-0 w-full h-full object-cover">
                @endif
            </div>
        </div>
    </section>
@endif
