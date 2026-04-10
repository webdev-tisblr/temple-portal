@props(['product'])

<a href="{{ route('store.product', $product->slug) }}"
   class="card-sacred group block {{ $product->inStock() ? '' : 'opacity-60' }}">
    <div class="aspect-[4/3] flex items-center justify-center relative overflow-hidden"
         style="background: radial-gradient(ellipse at bottom, #2a1508, #0f0804);">
        @if($product->image_path)
            <img src="{{ asset('storage/' . $product->image_path) }}"
                 alt="{{ $product->name }}"
                 class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700 opacity-80 group-hover:opacity-100">
        @else
            <div class="text-center">
                <svg class="w-16 h-16 text-amber-800/40 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                          d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                </svg>
            </div>
        @endif

        {{-- Category Badge --}}
        @if($product->category)
            <span class="absolute top-3 left-3 px-2.5 py-1 text-[9px] font-bold uppercase tracking-widest rounded-full bg-black/50 backdrop-blur-sm text-amber-300 border border-amber-800/30">
                {{ $product->category->name }}
            </span>
        @endif

        {{-- Stock Badge --}}
        @if($product->inStock())
            <span class="absolute top-3 right-3 px-2 py-0.5 text-[9px] font-bold uppercase tracking-wider rounded-full bg-emerald-950/60 backdrop-blur-sm text-emerald-400 border border-emerald-800/30">
                In Stock
            </span>
        @else
            <span class="absolute top-3 right-3 px-2 py-0.5 text-[9px] font-bold uppercase tracking-wider rounded-full bg-red-950/60 backdrop-blur-sm text-red-400 border border-red-800/30">
                Out of Stock
            </span>
        @endif
    </div>

    <div class="p-5">
        <h3 class="text-lg font-bold text-gold group-hover:text-amber-300 transition-colors">{{ $product->name }}</h3>
        @if($product->description)
            <p class="text-sm text-amber-100/30 mt-1.5 line-clamp-2">{{ strip_tags($product->description) }}</p>
        @endif
        <div class="flex items-center justify-between mt-4 pt-4 border-t border-amber-900/20">
            <span class="text-xl font-black text-gold">{!! $product->getDisplayPrice() !!}</span>
            <span class="text-amber-600 text-sm font-semibold group-hover:translate-x-1 transition-transform flex items-center gap-1">
                વિગત <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </span>
        </div>
    </div>
</a>
