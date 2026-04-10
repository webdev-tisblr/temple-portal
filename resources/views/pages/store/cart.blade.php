@extends('layouts.app')

@section('content')

<section class="bg-temple-light border-b border-amber-900/20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        <nav class="flex items-center gap-2 text-sm text-amber-100/30 mb-4">
            <a href="{{ route('home') }}" class="hover:text-gold transition">મુખ્ય પૃષ્ઠ</a>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <a href="{{ route('store.index') }}" class="hover:text-gold transition">સ્ટોર</a>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <span class="text-gold font-medium">કાર્ટ</span>
        </nav>
        <h1 class="divine-heading text-3xl sm:text-4xl">કાર્ટ</h1>
    </div>
</section>

<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-10 bg-temple">

    {{-- Flash / Error Messages --}}
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

    @if(empty($items))
        {{-- Empty Cart --}}
        <div class="text-center py-20">
            <div class="w-20 h-20 bg-amber-900/20 border border-amber-800/30 rounded-full flex items-center justify-center mx-auto mb-6">
                <svg class="w-10 h-10 text-amber-700/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/>
                </svg>
            </div>
            <h2 class="text-xl font-bold text-amber-100/60 mb-2">તમારી કાર્ટ ખાલી છે.</h2>
            <p class="text-amber-100/30 text-sm mb-6">મંદિર સ્ટોરમાંથી પૂજા સામગ્રી ખરીદો.</p>
            <a href="{{ route('store.index') }}" class="inline-flex items-center gap-2 btn-divine px-6 py-2.5">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                સ્ટોર પર જાઓ
            </a>
        </div>
    @else
        {{-- Cart with Items --}}
        <div x-data="cartManager()" x-cloak>

            {{-- Cart Items --}}
            <div class="card-sacred p-4 sm:p-6 inner-glow mb-8">
                <h2 class="text-gold font-bold text-lg mb-5 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
                    કાર્ટ આઇટમ
                </h2>

                <div class="space-y-4">
                    <template x-for="(item, index) in cartItems" :key="item.cart_key">
                        <div class="flex items-center gap-4 p-4 bg-amber-900/10 border border-amber-900/20 rounded-xl">
                            {{-- Product Image --}}
                            <div class="w-16 h-16 sm:w-20 sm:h-20 rounded-lg overflow-hidden border border-amber-800/20 flex-shrink-0">
                                <template x-if="item.image">
                                    <img :src="item.image" :alt="item.name" class="w-full h-full object-cover">
                                </template>
                                <template x-if="!item.image">
                                    <div class="w-full h-full flex items-center justify-center" style="background: linear-gradient(145deg, #1a0f08, #0f0804);">
                                        <svg class="w-8 h-8 text-amber-800/40" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                                    </div>
                                </template>
                            </div>

                            {{-- Details --}}
                            <div class="flex-1 min-w-0">
                                <a :href="item.url" class="text-sm sm:text-base font-semibold text-amber-100/80 hover:text-gold transition truncate block" x-text="item.name"></a>
                                <p class="text-sm text-amber-100/40 mt-0.5">
                                    ₹<span x-text="parseFloat(item.unit_price).toLocaleString('en-IN', {minimumFractionDigits: 2})"></span> પ્રતિ નંગ
                                </p>
                            </div>

                            {{-- Quantity --}}
                            <div class="flex items-center gap-1">
                                <button @click="decrementQty(index)" class="w-8 h-8 flex items-center justify-center rounded-lg border border-amber-800/30 text-amber-100/50 hover:text-gold hover:border-amber-600 transition" :disabled="item.quantity <= 1" :class="item.quantity <= 1 ? 'opacity-30 cursor-not-allowed' : ''">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"/></svg>
                                </button>
                                <input type="number" :value="item.quantity" @change="updateQtyFromInput(index, $event.target.value)" min="1" class="w-12 h-8 text-center bg-transparent border border-amber-800/30 rounded-lg text-sm text-amber-100 focus:border-amber-600 focus:ring-1 focus:ring-amber-600/20 [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none">
                                <button @click="incrementQty(index)" class="w-8 h-8 flex items-center justify-center rounded-lg border border-amber-800/30 text-amber-100/50 hover:text-gold hover:border-amber-600 transition">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                </button>
                            </div>

                            {{-- Subtotal --}}
                            <div class="hidden sm:block text-right min-w-[80px]">
                                <span class="text-sm font-bold text-gold">₹<span x-text="parseFloat(item.subtotal).toLocaleString('en-IN', {minimumFractionDigits: 2})"></span></span>
                            </div>

                            {{-- Remove --}}
                            <button @click="removeItem(index)" class="p-2 text-amber-100/30 hover:text-red-400 transition flex-shrink-0" title="દૂર કરો">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>
                    </template>
                </div>

                {{-- Cart Total --}}
                <div class="mt-6 pt-5 border-t border-amber-900/20 flex items-center justify-between">
                    <span class="text-amber-100/50 font-medium">કુલ રકમ</span>
                    <span class="text-2xl font-bold text-gold">₹<span x-text="parseFloat(cartTotal).toLocaleString('en-IN', {minimumFractionDigits: 2})"></span></span>
                </div>
            </div>

            {{-- Shipping & Checkout Form --}}
            <form method="POST" action="{{ route('store.checkout') }}" id="checkoutForm">
                @csrf

                <div class="card-sacred p-4 sm:p-6 inner-glow mb-8">
                    <h2 class="text-gold font-bold text-lg mb-6 flex items-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        શિપિંગ માહિતી
                    </h2>

                    <div class="space-y-5">
                        {{-- Name + Phone --}}
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <div>
                                <label for="shipping_name" class="block text-sm font-medium text-amber-600 mb-1.5">નામ <span class="text-red-400">*</span></label>
                                <input type="text" name="shipping_name" id="shipping_name" required
                                    value="{{ old('shipping_name', auth('devotee')->user()->name ?? '') }}"
                                    placeholder="તમારું પૂરું નામ"
                                    class="w-full px-4 py-2.5 bg-transparent border border-amber-800/30 rounded-lg text-sm text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-1 focus:ring-amber-600/20">
                                @error('shipping_name')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="shipping_phone" class="block text-sm font-medium text-amber-600 mb-1.5">ફોન <span class="text-red-400">*</span></label>
                                <input type="text" name="shipping_phone" id="shipping_phone" required
                                    value="{{ old('shipping_phone', auth('devotee')->user()->phone ?? '') }}"
                                    placeholder="+91 XXXXX XXXXX"
                                    class="w-full px-4 py-2.5 bg-transparent border border-amber-800/30 rounded-lg text-sm text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-1 focus:ring-amber-600/20">
                                @error('shipping_phone')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                            </div>
                        </div>

                        {{-- Address --}}
                        <div>
                            <label for="shipping_address" class="block text-sm font-medium text-amber-600 mb-1.5">સરનામું <span class="text-red-400">*</span></label>
                            <textarea name="shipping_address" id="shipping_address" rows="3" required
                                placeholder="તમારું પૂરું સરનામું"
                                class="w-full px-4 py-2.5 bg-transparent border border-amber-800/30 rounded-lg text-sm text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-1 focus:ring-amber-600/20 resize-none">{{ old('shipping_address', auth('devotee')->user()->address ?? '') }}</textarea>
                            @error('shipping_address')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                        </div>

                        {{-- City + State --}}
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <div>
                                <label for="shipping_city" class="block text-sm font-medium text-amber-600 mb-1.5">શહેર <span class="text-red-400">*</span></label>
                                <input type="text" name="shipping_city" id="shipping_city" required
                                    value="{{ old('shipping_city', auth('devotee')->user()->city ?? '') }}"
                                    placeholder="તમારું શહેર"
                                    class="w-full px-4 py-2.5 bg-transparent border border-amber-800/30 rounded-lg text-sm text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-1 focus:ring-amber-600/20">
                                @error('shipping_city')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="shipping_state" class="block text-sm font-medium text-amber-600 mb-1.5">રાજ્ય <span class="text-red-400">*</span></label>
                                <input type="text" name="shipping_state" id="shipping_state" required
                                    value="{{ old('shipping_state', auth('devotee')->user()->state ?? '') }}"
                                    placeholder="તમારું રાજ્ય"
                                    class="w-full px-4 py-2.5 bg-transparent border border-amber-800/30 rounded-lg text-sm text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-1 focus:ring-amber-600/20">
                                @error('shipping_state')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                            </div>
                        </div>

                        {{-- Pincode --}}
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <div>
                                <label for="shipping_pincode" class="block text-sm font-medium text-amber-600 mb-1.5">પિનકોડ <span class="text-red-400">*</span></label>
                                <input type="text" name="shipping_pincode" id="shipping_pincode" required maxlength="10"
                                    value="{{ old('shipping_pincode', auth('devotee')->user()->pincode ?? '') }}"
                                    placeholder="370205"
                                    class="w-full px-4 py-2.5 bg-transparent border border-amber-800/30 rounded-lg text-sm text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-1 focus:ring-amber-600/20">
                                @error('shipping_pincode')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                            </div>
                        </div>

                        {{-- Notes --}}
                        <div>
                            <label for="notes" class="block text-sm font-medium text-amber-600 mb-1.5">નોંધ <span class="text-xs text-amber-100/30 font-normal">(વૈકલ્પિક)</span></label>
                            <textarea name="notes" id="notes" rows="2"
                                placeholder="કોઈ ખાસ સૂચના..."
                                class="w-full px-4 py-2.5 bg-transparent border border-amber-800/30 rounded-lg text-sm text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-1 focus:ring-amber-600/20 resize-none">{{ old('notes') }}</textarea>
                            @error('notes')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </div>

                {{-- Checkout Button --}}
                <div class="text-center">
                    <button type="submit" class="btn-divine inline-flex items-center gap-2 px-10 py-3.5 text-base font-bold" :disabled="cartItems.length === 0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                        પેમેન્ટ કરો — ₹<span x-text="parseFloat(cartTotal).toLocaleString('en-IN', {minimumFractionDigits: 2})"></span>
                    </button>
                </div>
            </form>

        </div>
    @endif
</div>

@endsection

@push('scripts')
<script>
function cartManager() {
    return {
        cartItems: @json($cartItemsJs ?? []),
        cartTotal: {{ (float) ($total ?? 0) }},
        updating: false,

        recalcTotals() {
            this.cartTotal = this.cartItems.reduce((sum, item) => {
                item.subtotal = item.unit_price * item.quantity;
                return sum + item.subtotal;
            }, 0);
        },

        async syncCart(cartKey, quantity) {
            this.updating = true;
            try {
                const res = await fetch('{{ route("store.cart.update") }}', {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ cart_key: cartKey, quantity: quantity }),
                });
                const data = await res.json();
                if (!data.success) {
                    console.error('Cart update failed');
                }
            } catch (e) {
                console.error('Cart sync error', e);
            } finally {
                this.updating = false;
            }
        },

        incrementQty(index) {
            this.cartItems[index].quantity++;
            this.recalcTotals();
            this.syncCart(this.cartItems[index].cart_key, this.cartItems[index].quantity);
        },

        decrementQty(index) {
            if (this.cartItems[index].quantity > 1) {
                this.cartItems[index].quantity--;
                this.recalcTotals();
                this.syncCart(this.cartItems[index].cart_key, this.cartItems[index].quantity);
            }
        },

        updateQtyFromInput(index, value) {
            const qty = parseInt(value);
            if (qty >= 1) {
                this.cartItems[index].quantity = qty;
                this.recalcTotals();
                this.syncCart(this.cartItems[index].cart_key, qty);
            }
        },

        async removeItem(index) {
            const item = this.cartItems[index];
            this.cartItems.splice(index, 1);
            this.recalcTotals();

            try {
                const res = await fetch('{{ route("store.cart.remove") }}', {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ cart_key: item.cart_key }),
                });
                const data = await res.json();
                if (!data.success) {
                    console.error('Remove failed');
                }
            } catch (e) {
                console.error('Remove error', e);
            }

            // If cart becomes empty, reload to show empty state
            if (this.cartItems.length === 0) {
                window.location.reload();
            }
        },
    };
}
</script>
@endpush
