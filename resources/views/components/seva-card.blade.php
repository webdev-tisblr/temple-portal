@props(['seva'])

<a href="{{ route('seva.show', $seva) }}" class="card-sacred group block">
    <div class="aspect-[4/3] flex items-center justify-center relative overflow-hidden" style="background: radial-gradient(ellipse at bottom, #2a1508, #0f0804);">
        @if($seva->image_path)
            <img src="{{ asset('storage/' . $seva->image_path) }}" alt="{{ $seva->name }}" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700 opacity-80 group-hover:opacity-100">
        @else
            <div class="text-center">
                <img src="{{ asset('images/shree-pataliya-hanumanji-logo.png') }}" alt="શ્રી પાતળિયા હનુમાનજી" class="w-20 h-20 rounded-full mx-auto diya-glow opacity-60" style="box-shadow: 0 0 30px rgba(196,154,42,0.2);">
            </div>
        @endif
        <span class="absolute top-3 left-3 px-2.5 py-1 text-[9px] font-bold uppercase tracking-widest rounded-full bg-black/50 backdrop-blur-sm text-amber-300 border border-amber-800/30">
            {{ $seva->getRawOriginal('category') }}
        </span>
    </div>
    <div class="p-5">
        <h3 class="text-lg font-bold text-gold group-hover:text-amber-300 transition-colors">{{ $seva->name }}</h3>
        @if($seva->description)
            <p class="text-sm text-amber-100/30 mt-1.5 line-clamp-2">{{ strip_tags($seva->description) }}</p>
        @endif
        <div class="flex items-center justify-between mt-4 pt-4 border-t border-amber-900/20">
            <div>
                @if($seva->is_variable_price)
                    <span class="text-[10px] text-amber-100/30 uppercase tracking-wider">ન્યૂનતમ</span><br>
                    <span class="text-xl font-black text-gold">₹{{ number_format((float) $seva->min_price) }}</span>
                @else
                    <span class="text-xl font-black text-gold">₹{{ number_format((float) $seva->price) }}</span>
                @endif
            </div>
            <span class="text-amber-600 text-sm font-semibold group-hover:translate-x-1 transition-transform flex items-center gap-1">
                વિગત <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </span>
        </div>
    </div>
</a>
