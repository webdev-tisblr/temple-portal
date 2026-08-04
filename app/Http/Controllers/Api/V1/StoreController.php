<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Jobs\GenerateStoreInvoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\SystemSetting;
use App\Services\RazorpayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StoreController extends BaseApiController
{
    public function categories(): JsonResponse
    {
        $categories = \App\Support\LocalizedCache::remember('store.categories', 900, function () {
            return ProductCategory::where('is_active', true)
                ->where('is_seva_only', false)
                ->orderBy('sort_order')
                ->get()
                ->map(fn (ProductCategory $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'name_gu' => $c->name_gu,
                    'name_hi' => $c->name_hi,
                    'name_en' => $c->name_en,
                    'slug' => $c->slug,
                    'image_url' => $c->image_path ? image_url($c->image_path) : null,
                ]);
        });

        return $this->success($categories);
    }

    public function products(Request $request): JsonResponse
    {
        $query = Product::query()
            ->active()
            ->forStore()
            ->orderBy('sort_order');

        if ($request->query('category_id')) {
            $query->where('category_id', $request->query('category_id'));
        }

        if ($request->query('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('name_gu', 'like', "%{$search}%")
                    ->orWhere('name_hi', 'like', "%{$search}%")
                    ->orWhere('name_en', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('featured')) {
            $query->where('is_featured', true);
        }

        $products = $query->paginate(20);

        $data = $products->getCollection()->map(fn (Product $p) => $this->mapProduct($p));

        return $this->success([
            'products' => $data,
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    public function productDetail(Product $product): JsonResponse
    {
        if (! $product->is_active || $product->is_seva_only
            || ($product->category && $product->category->is_seva_only)) {
            return $this->error('Product not found', 404);
        }

        return $this->success($this->mapProduct($product));
    }

    public function createOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:temple_products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.variant_label' => 'nullable|string|max:100',
            // Keep these limits in lockstep with StoreCheckoutRequest (web)
            // and cart_screen.dart (mobile). See that request class for
            // the rationale behind each cap.
            'shipping_name' => 'required|string|max:255',
            'shipping_phone' => 'required|string|max:15',
            'shipping_address' => 'required|string|max:500',
            'shipping_city' => 'required|string|max:100',
            'shipping_state' => 'required|string|max:100',
            'shipping_pincode' => 'required|string|max:6',
            'notes' => 'nullable|string|max:500',
        ]);

        $devotee = $request->user();

        try {
            $result = DB::transaction(function () use ($validated, $devotee) {
                $subtotal = 0;
                $orderItems = [];

                foreach ($validated['items'] as $item) {
                    $product = Product::findOrFail($item['product_id']);
                    if (! $product->is_active) {
                        throw new \RuntimeException("Product '{$product->name}' is no longer available.");
                    }

                    // Stock validation — variant-aware. The web checkout
                    // does the same check before payment; API was missing
                    // it entirely, meaning the mobile app could submit
                    // an order for an out-of-stock variant and the order
                    // would be created with status=pending until the user
                    // either paid (and stock went negative) or abandoned
                    // (and the order sat as a ghost).
                    $variantLabel = $item['variant_label'] ?? null;
                    // Untracked products are unlimited.
                    $available = ! $product->track_stock
                        ? PHP_INT_MAX
                        : ($variantLabel && $product->has_variants
                            ? ($product->getVariantStock($variantLabel) ?? 0)
                            : $product->stock_quantity);

                    if ($available < (int) $item['quantity']) {
                        $label = $variantLabel ? " ({$variantLabel})" : '';
                        throw new \RuntimeException(
                            "Not enough stock for '{$product->name}{$label}'. Available: {$available}, requested: {$item['quantity']}."
                        );
                    }

                    $unitPrice = (float) $product->price;

                    if (! empty($variantLabel) && $product->has_variants) {
                        $variantPrice = $product->getVariantPrice($variantLabel);
                        if ($variantPrice !== null) {
                            $unitPrice = (float) $variantPrice;
                        }
                    }

                    $itemSubtotal = $unitPrice * $item['quantity'];
                    $subtotal += $itemSubtotal;

                    $orderItems[] = [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'variant_label' => $variantLabel,
                        'quantity' => $item['quantity'],
                        'unit_price' => $unitPrice,
                        'subtotal' => $itemSubtotal,
                    ];
                    // NB: stock is NOT decremented here. PaymentCaptureService
                    // does it after Razorpay confirms, so abandoned-payment
                    // flows don't leak stock. The validation above prevents
                    // creating an order we know can't be fulfilled.
                }

                $receipt = 'ORDER-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
                $razorpayService = app(RazorpayService::class);
                $amountInPaise = (int) round($subtotal * 100);

                $razorpayOrder = $razorpayService->createOrder($amountInPaise, $receipt, [
                    'devotee_id' => $devotee->id,
                    'type' => 'store_order',
                ]);

                $payment = Payment::create([
                    'id' => (string) Str::uuid(),
                    'razorpay_order_id' => $razorpayOrder->id,
                    'amount' => $subtotal,
                    'currency' => 'INR',
                    'status' => 'created',
                    'description' => 'Store order',
                ]);

                $order = Order::create([
                    'id' => (string) Str::uuid(),
                    'devotee_id' => $devotee->id,
                    'payment_id' => $payment->id,
                    'subtotal' => $subtotal,
                    'shipping_charge' => 0,
                    'total_amount' => $subtotal,
                    'status' => 'pending',
                    'shipping_name' => $validated['shipping_name'],
                    'shipping_phone' => $validated['shipping_phone'],
                    'shipping_address' => $validated['shipping_address'],
                    'shipping_city' => $validated['shipping_city'],
                    'shipping_state' => $validated['shipping_state'],
                    'shipping_pincode' => $validated['shipping_pincode'],
                    'notes' => $validated['notes'] ?? null,
                ]);

                foreach ($orderItems as $oi) {
                    OrderItem::create(array_merge($oi, ['order_id' => $order->id]));
                }

                return [
                    'order' => $order,
                    'payment' => $payment,
                    'razorpay_order' => $razorpayOrder,
                    'subtotal' => $subtotal,
                ];
            });

            Log::info('Store order created, awaiting payment', [
                'order_id' => $result['order']->id,
                'razorpay_order_id' => $result['razorpay_order']->id,
                'amount' => $result['subtotal'],
            ]);

            return $this->success([
                'order_id' => $result['order']->id,
                'order_number' => $result['order']->order_number,
                'total_amount' => (float) $result['order']->total_amount,
                'amount_paise' => (int) round($result['subtotal'] * 100),
                'status' => 'pending',
                'razorpay_order_id' => $result['razorpay_order']->id,
                'razorpay_key_id' => SystemSetting::getValue('razorpay_key_id', config('razorpay.key_id')),
                'devotee_name' => $devotee->name,
                'devotee_phone' => $devotee->phone,
                'devotee_email' => $devotee->email,
            ], 'ઓર્ડર બનાવ્યો. પેમેન્ટ પૂર્ણ કરો.');

        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('Store order failed', ['error' => $e->getMessage()]);
            return $this->error('ઓર્ડર નિષ્ફળ. ફરી પ્રયાસ કરો.', 500);
        }
    }

    public function orders(Request $request): JsonResponse
    {
        $orders = Order::with('items')
            ->where('devotee_id', $request->user()->id)
            ->whereHas('payment', fn ($q) => $q->where('status', 'captured'))
            ->orderByDesc('created_at')
            ->paginate(20);

        $data = $orders->getCollection()->map(fn (Order $o) => [
            'id' => $o->id,
            'order_number' => $o->order_number,
            'total_amount' => (float) $o->total_amount,
            'status' => $o->status->value,
            'items_count' => $o->items->count(),
            'items' => $o->items->map(fn (OrderItem $i) => [
                'product_name' => $i->product_name,
                'variant_label' => $i->variant_label,
                'quantity' => $i->quantity,
                'unit_price' => (float) $i->unit_price,
                'subtotal' => (float) $i->subtotal,
            ]),
            'shipping_name' => $o->shipping_name,
            'shipping_city' => $o->shipping_city,
            'created_at' => $o->created_at?->toISOString(),
        ]);

        return $this->success([
            'orders' => $data,
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    public function downloadInvoice(Request $request, Order $order)
    {
        if ($order->devotee_id !== $request->user()->id) {
            return $this->error('Unauthorized', 403);
        }
        if ($order->status?->value !== 'confirmed' && $order->status?->value !== 'shipped'
            && $order->status?->value !== 'delivered') {
            return $this->error('Invoice is generated only after the order is confirmed.', 404);
        }

        // Use InvoiceService directly (not the GenerateStoreInvoice job)
        // so the customer doesn't get re-emailed on every download. No R2
        // ->exists() probe — S3 HEADs from Hostinger hang, and the sweep
        // NULLs invoice_path when it deletes the object, so non-null == present.
        if (! $order->invoice_path) {
            try {
                app(\App\Services\InvoiceService::class)->generateInvoice($order);
                $order->refresh();
            } catch (\Throwable $e) {
                Log::error('On-demand invoice regen failed (api)', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
            if (! $order->invoice_path) {
                return $this->error('Invoice could not be generated. Try again shortly.', 500);
            }
        }

        // Redirect to a presigned R2 URL instead of proxying bytes through PHP.
        $filename = "Invoice_{$order->order_number}.pdf";

        return private_file_redirect($order->invoice_path, $filename);
    }

    private function mapProduct(Product $p): array
    {
        // Variant payload — every variant carries label, price AND
        // stock for variable products. The mobile app uses these to
        // gate the picker (greyed-out / "Out of stock" per option)
        // and to show "n left" badges.
        $variants = null;
        if ($p->has_variants && ! empty($p->variants)) {
            // Untracked products: every variant reads as available with
            // no count (the app's `in_stock` default is true, so old
            // versions behave identically).
            $variants = collect($p->variants)->map(fn ($v) => [
                'label' => $v['label'] ?? '',
                'price' => (float) ($v['price'] ?? 0),
                'stock' => $p->track_stock ? (int) ($v['stock'] ?? 0) : null,
                'in_stock' => $p->track_stock ? (int) ($v['stock'] ?? 0) > 0 : true,
            ])->all();
        }

        return [
            'id' => $p->id,
            'name' => $p->name,
            'name_gu' => $p->name_gu,
            'name_hi' => $p->name_hi,
            'name_en' => $p->name_en,
            'description' => $p->description,
            'slug' => $p->slug,
            'category_id' => $p->category_id,
            'price' => (float) $p->price,
            // For variable products `stock_quantity` is the SUM across
            // all variants — display only; the source of truth for
            // ordering is the per-variant `stock` field above.
            // Untracked products report null (no count to show).
            'stock_quantity' => ! $p->track_stock
                ? null
                : ($p->has_variants
                    ? collect($p->variants ?? [])->sum(fn ($v) => (int) ($v['stock'] ?? 0))
                    : $p->stock_quantity),
            'track_stock' => $p->track_stock,
            'in_stock' => $p->inStock(),
            'image_url' => $p->image_path ? image_url($p->image_path) : null,
            'is_featured' => $p->is_featured,
            'has_variants' => $p->has_variants,
            'variants' => $variants,
        ];
    }
}
