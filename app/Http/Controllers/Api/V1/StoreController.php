<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StoreController extends BaseApiController
{
    public function categories(): JsonResponse
    {
        $categories = Cache::remember('store.categories', 900, function () {
            return ProductCategory::where('is_active', true)
                ->orderBy('sort_order')
                ->get()
                ->map(fn (ProductCategory $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'name_gu' => $c->name_gu,
                    'name_hi' => $c->name_hi,
                    'name_en' => $c->name_en,
                    'slug' => $c->slug,
                    'image_url' => $c->image_path ? asset('storage/' . $c->image_path) : null,
                ]);
        });

        return $this->success($categories);
    }

    public function products(Request $request): JsonResponse
    {
        $query = Product::where('is_active', true)
            ->where('is_seva_only', false)
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
        if (! $product->is_active) {
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
            'shipping_name' => 'required|string|max:255',
            'shipping_phone' => 'required|string|max:15',
            'shipping_address' => 'required|string|max:1000',
            'shipping_city' => 'required|string|max:100',
            'shipping_state' => 'required|string|max:100',
            'shipping_pincode' => 'required|string|max:10',
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $result = DB::transaction(function () use ($validated, $request) {
                $subtotal = 0;
                $orderItems = [];

                foreach ($validated['items'] as $item) {
                    $product = Product::findOrFail($item['product_id']);
                    $unitPrice = $product->price;

                    if (! empty($item['variant_label']) && $product->has_variants) {
                        $variantPrice = $product->getVariantPrice($item['variant_label']);
                        if ($variantPrice !== null) {
                            $unitPrice = $variantPrice;
                        }
                    }

                    $itemSubtotal = $unitPrice * $item['quantity'];
                    $subtotal += $itemSubtotal;

                    $orderItems[] = [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'variant_label' => $item['variant_label'] ?? null,
                        'quantity' => $item['quantity'],
                        'unit_price' => $unitPrice,
                        'subtotal' => $itemSubtotal,
                    ];
                }

                $payment = Payment::create([
                    'id' => (string) Str::uuid(),
                    'razorpay_order_id' => 'test_' . Str::random(14),
                    'amount' => $subtotal,
                    'currency' => 'INR',
                    'status' => 'captured',
                    'method' => 'test',
                    'paid_at' => now(),
                    'description' => 'Store order',
                ]);

                $order = Order::create([
                    'id' => (string) Str::uuid(),
                    'devotee_id' => $request->user()->id,
                    'payment_id' => $payment->id,
                    'subtotal' => $subtotal,
                    'shipping_charge' => 0,
                    'total_amount' => $subtotal,
                    'status' => 'confirmed',
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

                return $order;
            });

            return $this->success([
                'order_id' => $result->id,
                'order_number' => $result->order_number,
                'total_amount' => (float) $result->total_amount,
                'status' => 'confirmed',
            ], 'ઓર્ડર સફળ!');

        } catch (\Exception $e) {
            return $this->error('ઓર્ડર નિષ્ફળ. ફરી પ્રયાસ કરો.', 500);
        }
    }

    public function orders(Request $request): JsonResponse
    {
        $orders = Order::with('items')
            ->where('devotee_id', $request->user()->id)
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

    private function mapProduct(Product $p): array
    {
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
            'stock_quantity' => $p->stock_quantity,
            'in_stock' => $p->inStock(),
            'image_url' => $p->image_path ? asset('storage/' . $p->image_path) : null,
            'is_featured' => $p->is_featured,
            'has_variants' => $p->has_variants,
            'variants' => $p->variants,
        ];
    }
}
