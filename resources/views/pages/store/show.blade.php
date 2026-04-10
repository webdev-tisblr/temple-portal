@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 bg-temple">

    {{-- Breadcrumb --}}
    <nav class="text-sm text-amber-100/30 mb-6">
        <a href="{{ route('home') }}" class="hover:text-gold transition">મુખ્ય પૃષ્ઠ</a>
        <span class="mx-2">/</span>
        <a href="{{ route('store.index') }}" class="hover:text-gold transition">મંદિર સ્ટોર</a>
        <span class="mx-2">/</span>
        @if($product->category)
            <a href="{{ route('store.category', $product->category->slug) }}" class="hover:text-gold transition">{{ $product->category->name }}</a>
            <span class="mx-2">/</span>
        @endif
        <span class="text-gold">{{ $product->name }}</span>
    </nav>

    {{-- Product Detail --}}
    <div class="card-sacred overflow-hidden" x-data="productPage()">
        <div class="flex flex-col lg:flex-row">

            {{-- Left: Image Gallery --}}
            <div class="lg:w-1/2 flex-shrink-0">
                {{-- Main Image --}}
                <div class="aspect-square flex items-center justify-center overflow-hidden"
                     style="background: radial-gradient(ellipse at bottom, #2a1508, #0f0804);">
                    <template x-if="currentImage">
                        <img :src="currentImage" alt="{{ $product->name }}"
                             class="w-full h-full object-cover transition-opacity duration-300">
                    </template>
                    <template x-if="!currentImage">
                        <div class="text-center">
                            <svg class="w-24 h-24 text-amber-800/40 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                      d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                            </svg>
                        </div>
                    </template>
                </div>

                {{-- Thumbnail Strip --}}
                <div x-show="images.length > 1" class="flex gap-2 p-4 overflow-x-auto">
                    <template x-for="(img, index) in images" :key="index">
                        <button @click="currentImage = img"
                                :class="currentImage === img ? 'border-amber-500 opacity-100' : 'border-amber-800/30 opacity-50 hover:opacity-80'"
                                class="w-16 h-16 flex-shrink-0 rounded-lg overflow-hidden border-2 transition">
                            <img :src="img" alt="" class="w-full h-full object-cover">
                        </button>
                    </template>
                </div>
            </div>

            {{-- Right: Product Details --}}
            <div class="lg:w-1/2 p-6 sm:p-8">
                {{-- Category Badge --}}
                @if($product->category)
                    <span class="inline-block px-3 py-1 text-xs font-medium rounded-full mb-3 bg-amber-900/30 text-amber-400">
                        {{ $product->category->name }}
                    </span>
                @endif

                <h1 class="divine-heading text-2xl sm:text-3xl">{{ $product->name }}</h1>

                {{-- Price --}}
                <div class="mt-4">
                    <span class="text-3xl font-black text-gold">₹{{ number_format((float) $product->price) }}</span>
                </div>

                {{-- Stock Status --}}
                <div class="mt-3">
                    @if($product->inStock())
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 text-xs font-semibold rounded-full bg-emerald-950/50 text-emerald-400 border border-emerald-800/30">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                            In Stock ({{ $product->stock_quantity }} ઉપલબ્ધ)
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 text-xs font-semibold rounded-full bg-red-950/50 text-red-400 border border-red-800/30">
                            <span class="w-1.5 h-1.5 rounded-full bg-red-400"></span>
                            Out of Stock
                        </span>
                    @endif
                </div>

                {{-- Description --}}
                @if($product->description)
                    <div class="mt-6 text-amber-100/60 leading-relaxed prose prose-invert prose-sm max-w-none">
                        {!! $product->description !!}
                    </div>
                @endif

                {{-- Add to Cart Section --}}
                <div class="mt-8 border-t border-amber-900/20 pt-6">
                    @auth('devotee')
                        @if($product->inStock())
                            {{-- Variant Selector --}}
                            @if($product->has_variants && !empty($product->variants))
                                <div class="mb-5">
                                    <label class="block text-sm font-medium text-amber-600 mb-2">વિકલ્પ પસંદ કરો</label>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach($product->variants as $i => $variant)
                                            <button type="button"
                                                @click="selectedVariant = {{ $i }}; selectedPrice = {{ (float) $variant['price'] }}; variantLabel = '{{ e($variant['label']) }}'"
                                                :class="selectedVariant === {{ $i }} ? 'bg-gradient-to-r from-amber-600 to-amber-500 text-stone-900 border-amber-500 font-bold' : 'bg-transparent text-amber-100/60 border-amber-800/30 hover:border-amber-600'"
                                                class="px-4 py-2.5 border rounded-lg text-sm font-medium transition">
                                                {{ $variant['label'] }} — ₹{{ number_format((float) $variant['price'], 2) }}
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                                <div class="mb-4">
                                    <span class="text-2xl font-black text-gold" x-text="'₹' + selectedPrice.toLocaleString('en-IN', {minimumFractionDigits: 2})"></span>
                                </div>
                            @endif

                            {{-- Quantity Selector --}}
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-amber-600 mb-2">જથ્થો</label>
                                <div class="inline-flex items-center border border-amber-800/30 rounded-lg overflow-hidden">
                                    <button type="button"
                                            @click="quantity > 1 ? quantity-- : null"
                                            class="px-3 py-2 text-amber-100/60 hover:text-amber-100 hover:bg-amber-900/30 transition">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"/>
                                        </svg>
                                    </button>
                                    <span class="px-5 py-2 text-amber-100 font-semibold text-center min-w-[3rem] border-x border-amber-800/30"
                                          x-text="quantity"></span>
                                    <button type="button"
                                            @click="quantity < maxStock ? quantity++ : null"
                                            class="px-3 py-2 text-amber-100/60 hover:text-amber-100 hover:bg-amber-900/30 transition">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            {{-- Add to Cart / Login Button --}}
                            @auth('devotee')
                                <button @click="addToCart()"
                                        :disabled="adding"
                                        class="w-full sm:w-auto px-8 py-3 btn-divine disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center gap-2">
                                    <svg x-show="!adding" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                              d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/>
                                    </svg>
                                    <svg x-show="adding" class="animate-spin w-5 h-5" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                    </svg>
                                    <span x-text="adding ? 'ઉમેરાઈ રહ્યું છે...' : 'કાર્ટમાં ઉમેરો'"></span>
                                </button>
                            @else
                                <a href="{{ route('login') }}" class="w-full sm:w-auto px-8 py-3 btn-divine inline-flex items-center justify-center gap-2">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/></svg>
                                    ખરીદવા લૉગિન કરો
                                </a>
                            @endauth

                            {{-- Success Toast --}}
                            <div x-show="showToast"
                                 x-transition:enter="transition ease-out duration-300"
                                 x-transition:enter-start="opacity-0 translate-y-2"
                                 x-transition:enter-end="opacity-100 translate-y-0"
                                 x-transition:leave="transition ease-in duration-200"
                                 x-transition:leave-start="opacity-100 translate-y-0"
                                 x-transition:leave-end="opacity-0 translate-y-2"
                                 class="mt-4 px-4 py-3 rounded-lg border text-sm flex items-center gap-2"
                                 :class="toastSuccess ? 'bg-emerald-950/50 border-emerald-800/30 text-emerald-400' : 'bg-red-950/50 border-red-800/30 text-red-400'">
                                <svg x-show="toastSuccess" class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                </svg>
                                <svg x-show="!toastSuccess" class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                <span x-text="toastMessage"></span>
                            </div>
                        @else
                            <button disabled class="w-full sm:w-auto px-8 py-3 btn-divine opacity-40 cursor-not-allowed">
                                Out of Stock
                            </button>
                        @endif
                    @else
                        <a href="{{ route('login') }}" class="inline-flex items-center px-8 py-3 btn-divine gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/>
                            </svg>
                            ખરીદવા માટે લૉગિન કરો
                        </a>
                    @endauth
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function productPage() {
    const primaryImage = @json($product->image_path ? asset('storage/' . $product->image_path) : null);
    const galleryImages = @json($product->images->sortBy('sort_order')->pluck('image_path')->map(fn($p) => asset('storage/' . $p))->values());

    let allImages = [];
    if (primaryImage) {
        allImages.push(primaryImage);
    }
    galleryImages.forEach(function(img) {
        if (allImages.indexOf(img) === -1) {
            allImages.push(img);
        }
    });

    const hasVariants = {{ $product->has_variants ? 'true' : 'false' }};
    const variants = @json($product->variants ?? []);
    const firstVariantPrice = variants.length > 0 ? parseFloat(variants[0].price) : {{ (float) $product->price }};

    return {
        images: allImages,
        currentImage: allImages.length > 0 ? allImages[0] : null,
        quantity: 1,
        maxStock: {{ $product->stock_quantity }},
        hasVariants: hasVariants,
        selectedVariant: hasVariants ? 0 : null,
        selectedPrice: hasVariants ? firstVariantPrice : {{ (float) $product->price }},
        variantLabel: hasVariants && variants.length > 0 ? variants[0].label : '',
        adding: false,
        showToast: false,
        toastSuccess: false,
        toastMessage: '',

        async addToCart() {
            this.adding = true;
            this.showToast = false;

            try {
                const response = await fetch('{{ route("store.cart.add") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        product_id: {{ $product->id }},
                        quantity: this.quantity,
                        variant_label: this.hasVariants ? this.variantLabel : null,
                    }),
                });

                if (response.redirected || response.status === 401 || response.status === 419) {
                    window.location.href = '{{ route("login") }}';
                    return;
                }

                const data = await response.json();

                this.toastSuccess = data.success;
                this.toastMessage = data.message;
                this.showToast = true;

                if (data.success) {
                    const cartBadge = document.querySelector('[data-cart-count]');
                    if (cartBadge) {
                        cartBadge.textContent = data.cart_count;
                        cartBadge.classList.remove('hidden');
                    }
                }
            } catch (error) {
                this.toastSuccess = false;
                this.toastMessage = 'કંઈક ખોટું થયું. ફરી પ્રયાસ કરો.';
                this.showToast = true;
            }

            this.adding = false;

            // Auto-hide toast
            setTimeout(() => { this.showToast = false; }, 4000);
        }
    };
}
</script>
@endpush
@endsection
