@props(['product'])

<a href="{{ route('store.product', $product->slug) }}"
   class="card-sacred group block {{ $product->inStock() ? '' : 'opacity-60' }}">
    <div class="aspect-[4/3] flex items-center justify-center relative overflow-hidden"
         style="background: radial-gradient(ellipse at bottom, #F4EAD5, #FBF5EA);">
        @if($product->image_path)
            <img src="{{ image_url($product->image_path) }}"
                 alt="{{ $product->name }}"
                 class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700">
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
            <span class="absolute top-3 left-3 px-2.5 py-1 text-[9px] font-bold uppercase tracking-widest rounded-full shadow-sm"
                  style="background: rgba(255,252,245,0.92); color: #7A1E1E; border: 1px solid rgba(200,148,52,0.55); backdrop-filter: blur(4px);">
                {{ $product->category->name }}
            </span>
        @endif

        {{-- Stock Badge --}}
        @if($product->inStock())
            <span class="absolute top-3 right-3 px-2 py-0.5 text-[9px] font-bold uppercase tracking-wider rounded-full shadow-sm"
                  style="background: rgba(63,122,63,0.14); color: #2D5F2D; border: 1px solid rgba(63,122,63,0.40);">
                In Stock
            </span>
        @else
            <span class="absolute top-3 right-3 px-2 py-0.5 text-[9px] font-bold uppercase tracking-wider rounded-full shadow-sm"
                  style="background: rgba(168,50,50,0.12); color: #7A1E1E; border: 1px solid rgba(168,50,50,0.40);">
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
                {{ __('common.details') }} <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </span>
        </div>
    </div>
</a>
