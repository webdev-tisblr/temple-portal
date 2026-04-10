<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCheckoutRequest;
use App\Jobs\GenerateStoreInvoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\RazorpayService;
use Artesaos\SEOTools\Facades\SEOMeta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class StoreWebController extends Controller
{
    public function index(): View
    {
        $categories = Cache::remember('store_categories_with_counts', 600, function () {
            return ProductCategory::where('is_active', true)
                ->withCount(['products' => fn ($q) => $q->active()])
                ->orderBy('sort_order')
                ->get();
        });

        $featured = Cache::remember('store_featured_products', 600, function () {
            return Product::active()
                ->forStore()
                ->featured()
                ->with('category')
                ->orderBy('sort_order')
                ->limit(8)
                ->get();
        });

        SEOMeta::setTitle('મંદિર સ્ટોર — શ્રી પાતળિયા હનુમાનજી સેવા ટ્રસ્ટ');
        SEOMeta::setDescription('શ્રી પાતળિયા હનુમાનજી મંદિર સ્ટોરમાંથી પૂજા સામગ્રી અને ધાર્મિક ચીજો ખરીદો.');

        return view('pages.store.index', compact('categories', 'featured'));
    }

    public function category(string $slug): View
    {
        $category = ProductCategory::where('slug', $slug)->where('is_active', true)->firstOrFail();

        $query = Product::where('category_id', $category->id)->active()->forStore();

        // Search filter
        if ($search = request('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name_gu', 'LIKE', "%{$search}%")
                    ->orWhere('name_hi', 'LIKE', "%{$search}%")
                    ->orWhere('name_en', 'LIKE', "%{$search}%");
            });
        }

        // Price filters
        if ($minPrice = request('min_price')) {
            $query->where('price', '>=', (float) $minPrice);
        }
        if ($maxPrice = request('max_price')) {
            $query->where('price', '<=', (float) $maxPrice);
        }

        // Sort
        $sort = request('sort', 'newest');
        $query = match ($sort) {
            'price_asc' => $query->orderBy('price', 'asc'),
            'price_desc' => $query->orderBy('price', 'desc'),
            default => $query->orderBy('created_at', 'desc'),
        };

        $products = $query->paginate(12)->withQueryString();

        SEOMeta::setTitle("{$category->name} — મંદિર સ્ટોર — શ્રી પાતળિયા હનુમાનજી સેવા ટ્રસ્ટ");
        SEOMeta::setDescription($category->description ?? '');

        return view('pages.store.category', compact('category', 'products'));
    }

    public function show(string $slug): View
    {
        $product = Product::where('slug', $slug)
            ->with(['images', 'category'])
            ->firstOrFail();

        if (! $product->is_active) {
            abort(404);
        }

        SEOMeta::setTitle("{$product->name} — મંદિર સ્ટોર — શ્રી પાતળિયા હનુમાનજી સેવા ટ્રસ્ટ");
        SEOMeta::setDescription($product->description ?? '');

        return view('pages.store.show', compact('product'));
    }

    public function addToCart(Request $request): JsonResponse
    {
        $request->validate([
            'product_id' => ['required', 'integer', 'exists:temple_products,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'variant_label' => ['nullable', 'string', 'max:100'],
        ]);

        $product = Product::where('id', $request->product_id)->active()->firstOrFail();

        // Validate variant exists if product has variants
        if ($product->has_variants) {
            if (empty($request->variant_label)) {
                return response()->json(['success' => false, 'message' => 'કૃપા કરીને વિકલ્પ પસંદ કરો.'], 422);
            }
            if ($product->getVariantPrice($request->variant_label) === null) {
                return response()->json(['success' => false, 'message' => 'અમાન્ય વિકલ્પ.'], 422);
            }
        }

        if ($product->stock_quantity < $request->quantity) {
            return response()->json(['success' => false, 'message' => 'પૂરતો સ્ટોક ઉપલબ્ધ નથી.'], 422);
        }

        $cart = session('cart', []);

        // Cart key: "productId" for simple, "productId:variantLabel" for variant
        $cartKey = $product->has_variants
            ? $product->id . ':' . $request->variant_label
            : (string) $product->id;

        if (isset($cart[$cartKey])) {
            $cart[$cartKey] += (int) $request->quantity;
        } else {
            $cart[$cartKey] = (int) $request->quantity;
        }

        session(['cart' => $cart]);

        return response()->json([
            'success' => true,
            'cart_count' => array_sum($cart),
            'message' => 'પ્રોડક્ટ કાર્ટમાં ઉમેરાઈ.',
        ]);
    }

    public function cart(): View
    {
        $cart = session('cart', []);
        $items = [];
        $total = 0;

        if (! empty($cart)) {
            // Extract unique product IDs from cart keys (format: "id" or "id:variant")
            $productIds = collect(array_keys($cart))->map(fn ($k) => (int) explode(':', (string) $k)[0])->unique()->toArray();
            $products = Product::whereIn('id', $productIds)->active()->get()->keyBy('id');

            foreach ($cart as $cartKey => $quantity) {
                $parts = explode(':', (string) $cartKey, 2);
                $productId = (int) $parts[0];
                $variantLabel = $parts[1] ?? null;

                if ($products->has($productId)) {
                    $product = $products->get($productId);

                    $unitPrice = $variantLabel
                        ? ($product->getVariantPrice($variantLabel) ?? (float) $product->price)
                        : (float) $product->price;

                    $subtotal = $unitPrice * $quantity;
                    $items[] = [
                        'product' => $product,
                        'cart_key' => $cartKey,
                        'variant_label' => $variantLabel,
                        'unit_price' => $unitPrice,
                        'quantity' => $quantity,
                        'subtotal' => $subtotal,
                    ];
                    $total += $subtotal;
                }
            }
        }

        $cartItemsJs = collect($items)->map(function ($item) {
            $name = $item['product']->name;
            if ($item['variant_label']) {
                $name .= ' — ' . $item['variant_label'];
            }

            return [
                'cart_key' => $item['cart_key'],
                'product_id' => $item['product']->id,
                'name' => $name,
                'variant_label' => $item['variant_label'],
                'url' => route('store.product', $item['product']->slug),
                'image' => $item['product']->image_path ? asset('storage/' . $item['product']->image_path) : null,
                'unit_price' => $item['unit_price'],
                'quantity' => $item['quantity'],
                'subtotal' => $item['subtotal'],
            ];
        })->values()->toArray();

        SEOMeta::setTitle('કાર્ટ — મંદિર સ્ટોર — શ્રી પાતળિયા હનુમાનજી સેવા ટ્રસ્ટ');

        return view('pages.store.cart', compact('items', 'total', 'cartItemsJs'));
    }

    public function updateCart(Request $request): JsonResponse
    {
        $request->validate([
            'cart_key' => ['required', 'string'],
            'quantity' => ['required', 'integer', 'min:0'],
        ]);

        $cart = session('cart', []);
        $cartKey = $request->cart_key;

        if ((int) $request->quantity === 0) {
            unset($cart[$cartKey]);
        } else {
            $cart[$cartKey] = (int) $request->quantity;
        }

        session(['cart' => $cart]);

        $cartTotal = $this->calculateCartTotal($cart);

        return response()->json([
            'success' => true,
            'cart_count' => array_sum($cart),
            'cart_total' => $cartTotal,
        ]);
    }

    public function removeFromCart(Request $request): JsonResponse
    {
        $request->validate([
            'cart_key' => ['required', 'string'],
        ]);

        $cart = session('cart', []);
        unset($cart[$request->cart_key]);

        session(['cart' => $cart]);

        return response()->json([
            'success' => true,
            'cart_count' => array_sum($cart),
            'message' => 'પ્રોડક્ટ કાર્ટમાંથી દૂર કરાઈ.',
        ]);
    }

    private function calculateCartTotal(array $cart): float
    {
        if (empty($cart)) {
            return 0;
        }

        $productIds = collect(array_keys($cart))->map(fn ($k) => (int) explode(':', (string) $k)[0])->unique()->toArray();
        $products = Product::whereIn('id', $productIds)->get()->keyBy('id');
        $total = 0;

        foreach ($cart as $cartKey => $qty) {
            $parts = explode(':', (string) $cartKey, 2);
            $productId = (int) $parts[0];
            $variantLabel = $parts[1] ?? null;

            if ($products->has($productId)) {
                $product = $products->get($productId);
                $price = $variantLabel
                    ? ($product->getVariantPrice($variantLabel) ?? (float) $product->price)
                    : (float) $product->price;
                $total += $price * $qty;
            }
        }

        return $total;
    }

    public function checkout(StoreCheckoutRequest $request): View|RedirectResponse
    {
        $validated = $request->validated();
        $devotee = Auth::guard('devotee')->user();
        $cart = session('cart', []);

        if (empty($cart)) {
            return redirect()->route('store.cart')->withErrors(['cart' => 'તમારી કાર્ટ ખાલી છે.']);
        }

        $productIds = collect(array_keys($cart))->map(fn ($k) => (int) explode(':', (string) $k)[0])->unique()->toArray();
        $products = Product::whereIn('id', $productIds)->active()->get()->keyBy('id');

        // Verify all products are in stock & build line items
        $lineItems = [];
        foreach ($cart as $cartKey => $quantity) {
            $parts = explode(':', (string) $cartKey, 2);
            $productId = (int) $parts[0];
            $variantLabel = $parts[1] ?? null;

            if (! $products->has($productId)) {
                return back()->withErrors(['cart' => 'કેટલીક પ્રોડક્ટ ઉપલબ્ધ નથી.']);
            }
            $product = $products->get($productId);
            if ($product->stock_quantity < $quantity) {
                return back()->withErrors(['cart' => "{$product->name} માટે પૂરતો સ્ટોક ઉપલબ્ધ નથી."]);
            }

            $unitPrice = $variantLabel
                ? ($product->getVariantPrice($variantLabel) ?? (float) $product->price)
                : (float) $product->price;

            $lineItems[] = [
                'product' => $product,
                'variant_label' => $variantLabel,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'subtotal' => $unitPrice * $quantity,
            ];
        }

        $subtotal = collect($lineItems)->sum('subtotal');
        $shippingCharge = 0;
        $totalAmount = $subtotal + $shippingCharge;

        // TEST MODE — skip Razorpay, direct confirm
        if (config('razorpay.test_mode')) {
            return $this->checkoutTestMode($validated, $devotee, $lineItems, $subtotal, $shippingCharge, $totalAmount);
        }

        // REAL PAYMENT MODE
        try {
            $result = DB::transaction(function () use ($validated, $devotee, $lineItems, $subtotal, $shippingCharge, $totalAmount) {
                $paymentId = (string) Str::uuid();
                $receipt = 'ORD-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));

                $razorpayService = app(RazorpayService::class);
                $amountInPaise = (int) round($totalAmount * 100);

                $razorpayOrder = $razorpayService->createOrder($amountInPaise, $receipt, [
                    'devotee_id' => $devotee->id,
                    'type' => 'store_order',
                ]);

                $payment = Payment::create([
                    'id' => $paymentId,
                    'razorpay_order_id' => $razorpayOrder->id,
                    'amount' => $totalAmount,
                    'currency' => 'INR',
                    'status' => 'created',
                    'description' => 'Store Order',
                ]);

                $order = Order::create([
                    'devotee_id' => $devotee->id,
                    'payment_id' => $payment->id,
                    'subtotal' => $subtotal,
                    'shipping_charge' => $shippingCharge,
                    'total_amount' => $totalAmount,
                    'status' => 'pending',
                    'shipping_name' => $validated['shipping_name'],
                    'shipping_phone' => $validated['shipping_phone'],
                    'shipping_address' => $validated['shipping_address'],
                    'shipping_city' => $validated['shipping_city'],
                    'shipping_state' => $validated['shipping_state'],
                    'shipping_pincode' => $validated['shipping_pincode'],
                    'notes' => $validated['notes'] ?? null,
                ]);

                foreach ($lineItems as $item) {
                    $product = $item['product'];
                    $productName = $product->name_en ?? $product->name_gu;
                    if ($item['variant_label']) {
                        $productName .= ' — ' . $item['variant_label'];
                    }
                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $product->id,
                        'product_name' => $productName,
                        'variant_label' => $item['variant_label'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'subtotal' => $item['subtotal'],
                    ]);
                    $product->decrementStock($item['quantity']);
                }

                return [
                    'order' => $order,
                    'payment' => $payment,
                    'razorpay_order' => $razorpayOrder,
                ];
            });

            return view('pages.seva.checkout', [
                'razorpayKeyId' => \App\Models\SystemSetting::getValue('razorpay_key_id', config('razorpay.key_id')),
                'orderId' => $result['razorpay_order']->id,
                'amount' => (int) round($totalAmount * 100),
                'currency' => 'INR',
                'description' => 'મંદિર સ્ટોર — ઓર્ડર',
                'devoteeName' => $devotee->name,
                'devoteePhone' => $devotee->phone,
                'devoteeEmail' => $devotee->email ?? '',
                'successUrl' => route('store.order.success'),
                'failureUrl' => route('store.order.failure'),
            ]);

        } catch (\Exception $e) {
            Log::error('Store checkout failed', ['error' => $e->getMessage()]);

            return back()->withErrors(['checkout' => 'ઓર્ડર બનાવવામાં નિષ્ફળ. કૃપા કરીને ફરી પ્રયાસ કરો.']);
        }
    }

    private function checkoutTestMode(array $validated, $devotee, array $lineItems, float $subtotal, float $shippingCharge, float $totalAmount): View|RedirectResponse
    {
        try {
            $result = DB::transaction(function () use ($validated, $devotee, $lineItems, $subtotal, $shippingCharge, $totalAmount) {
                $paymentId = (string) Str::uuid();

                $payment = Payment::create([
                    'id' => $paymentId,
                    'razorpay_order_id' => 'test_' . Str::random(14),
                    'amount' => $totalAmount,
                    'currency' => 'INR',
                    'status' => 'captured',
                    'method' => 'test',
                    'paid_at' => now(),
                    'description' => 'Store Order (Test)',
                ]);

                $order = Order::create([
                    'devotee_id' => $devotee->id,
                    'payment_id' => $payment->id,
                    'subtotal' => $subtotal,
                    'shipping_charge' => $shippingCharge,
                    'total_amount' => $totalAmount,
                    'status' => 'confirmed',
                    'shipping_name' => $validated['shipping_name'],
                    'shipping_phone' => $validated['shipping_phone'],
                    'shipping_address' => $validated['shipping_address'],
                    'shipping_city' => $validated['shipping_city'],
                    'shipping_state' => $validated['shipping_state'],
                    'shipping_pincode' => $validated['shipping_pincode'],
                    'notes' => $validated['notes'] ?? null,
                ]);

                foreach ($lineItems as $item) {
                    $product = $item['product'];
                    $productName = $product->name_en ?? $product->name_gu;
                    if ($item['variant_label']) {
                        $productName .= ' — ' . $item['variant_label'];
                    }
                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $product->id,
                        'product_name' => $productName,
                        'variant_label' => $item['variant_label'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'subtotal' => $item['subtotal'],
                    ]);
                    $product->decrementStock($item['quantity']);
                }

                return ['order' => $order, 'payment' => $payment];
            });

            Log::info('Store order confirmed (test mode)', ['order_id' => $result['order']->id]);

            // Clear cart
            session()->forget('cart');

            // Generate invoice
            GenerateStoreInvoice::dispatchSync($result['order']);

            return view('pages.store.order-success', [
                'verified' => true,
                'order' => $result['order']->load('items'),
            ]);

        } catch (\Exception $e) {
            Log::error('Store checkout failed (test mode)', ['error' => $e->getMessage()]);

            return back()->withErrors(['checkout' => 'ઓર્ડર બનાવવામાં નિષ્ફળ. કૃપા કરીને ફરી પ્રયાસ કરો.']);
        }
    }

    public function orderSuccess(Request $request): View
    {
        $paymentId = $request->query('payment_id');
        $orderId = $request->query('order_id');
        $signature = $request->query('signature');

        $verified = false;
        $order = null;

        if ($paymentId && $orderId && $signature) {
            $razorpayService = app(RazorpayService::class);
            $verified = $razorpayService->verifyPaymentSignature($orderId, $paymentId, $signature);

            if ($verified) {
                $payment = Payment::where('razorpay_order_id', $orderId)->first();
                if ($payment) {
                    $payment->update([
                        'status' => 'captured',
                        'razorpay_payment_id' => $paymentId,
                        'paid_at' => $payment->paid_at ?? now(),
                    ]);

                    $order = Order::where('payment_id', $payment->id)->with('items')->first();

                    if ($order && $order->status->value !== 'confirmed') {
                        $order->update(['status' => 'confirmed']);
                    }

                    // Clear cart
                    session()->forget('cart');

                    // Generate invoice
                    if ($order) {
                        GenerateStoreInvoice::dispatchSync($order);
                    }
                }
            }
        }

        return view('pages.store.order-success', compact('verified', 'order'));
    }

    public function orderFailure(): View
    {
        return view('pages.store.order-failure');
    }

    public function downloadInvoice(Order $order)
    {
        $devotee = Auth::guard('devotee')->user();

        if ($order->devotee_id !== $devotee->id) {
            abort(403);
        }

        if (! $order->invoice_path || ! Storage::disk('local')->exists($order->invoice_path)) {
            abort(404, 'ઇનવૉઇસ ઉપલબ્ધ નથી.');
        }

        return Storage::disk('local')->download(
            $order->invoice_path,
            "Invoice_{$order->order_number}.pdf",
            ['Content-Type' => 'application/pdf']
        );
    }
}
