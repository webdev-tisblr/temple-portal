<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Order;
use App\Models\SystemSetting;
use App\Services\InvoiceService;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateStoreInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public Order $order,
    ) {}

    public function handle(InvoiceService $invoiceService): void
    {
        $path = $invoiceService->generateInvoice($this->order);

        Log::info('Store invoice generated', [
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
        ]);

        $this->order->loadMissing('devotee', 'items', 'payment');
        $devotee = $this->order->devotee;

        if (! $devotee) {
            return;
        }

        // ALWAYS dispatch the trigger, regardless of whether the buyer
        // has an email on file. Each enabled NotificationTemplate
        // handles its own recipient strategy: email uses devotee.email
        // (skips when null), WhatsApp / SMS use devotee.phone, etc.
        // Earlier this was gated on `$devotee->email && $path` which
        // meant a phone-only buyer never got WhatsApp / SMS even when
        // those templates were enabled by admin.
        $this->dispatchOrderConfirmed($devotee, $path);
    }

    private function dispatchOrderConfirmed($devotee, ?string $path): void
    {
        try {
            // Attach the invoice PDF when available; email driver uses
            // _attachments, other channels ignore it.
            $attachments = [];
            if ($path) {
                try {
                    $attachments[] = [
                        'data' => Storage::disk('r2_private')->get($path),
                        'name' => "Invoice_{$this->order->order_number}.pdf",
                        'mime' => 'application/pdf',
                    ];
                } catch (\Throwable $e) {
                    Log::warning('Store invoice PDF unreadable, dispatching without attachment', [
                        'order_id' => $this->order->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            app(NotificationService::class)->dispatch(
                'store.order.confirmed',
                [
                    'devotee' => $devotee,
                    'order' => array_merge($this->order->toArray(), [
                        'total_amount_formatted' => number_format((float) $this->order->total_amount, 2),
                        'items_count' => $this->order->items->count(),
                    ]),
                    'trust_name' => SystemSetting::getValue('trust_name', 'Shree Patadiya Hanumanji Seva Trust'),
                    '_attachments' => $attachments,
                ],
                idempotencyKey: "order:{$this->order->id}:confirmed",
            );

            Log::info('Store order dispatched via NotificationService', [
                'order_id' => $this->order->id,
                'email' => $devotee->email,
                'phone' => $devotee->phone,
                'has_pdf' => $attachments !== [],
            ]);
        } catch (\Exception $e) {
            Log::error('Store order dispatch failed', [
                'order_id' => $this->order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @deprecated body now lives in notification_templates; kept inert. */
    private function buildEmailHtmlUnused(Order $order): string
    {
        $orderNumber = e($order->order_number);
        $orderDate = $order->created_at->format('d M Y, h:i A');
        $shippingName = e($order->shipping_name);
        $shippingPhone = e($order->shipping_phone);
        $address = e(collect([$order->shipping_address, $order->shipping_city, $order->shipping_state, $order->shipping_pincode])->filter()->implode(', '));
        $subtotal = number_format((float) $order->subtotal, 2);
        $total = number_format((float) $order->total_amount, 2);
        $shipping = (float) $order->shipping_charge;

        $itemRows = '';
        foreach ($order->items as $i => $item) {
            $bg = ($i % 2 === 0) ? '#f9f5ef' : '#ffffff';
            $name = e($item->product_name);
            $qty = $item->quantity;
            $unit = number_format((float) $item->unit_price, 2);
            $sub = number_format((float) $item->subtotal, 2);
            $itemRows .= "<tr style=\"background:{$bg};\"><td style=\"padding:8px 12px;border-bottom:1px solid #eee;\">{$name}</td><td style=\"padding:8px 12px;border-bottom:1px solid #eee;text-align:center;\">{$qty}</td><td style=\"padding:8px 12px;border-bottom:1px solid #eee;text-align:right;\">₹{$unit}</td><td style=\"padding:8px 12px;border-bottom:1px solid #eee;text-align:right;font-weight:600;\">₹{$sub}</td></tr>";
        }

        $shippingRow = '';
        if ($shipping > 0) {
            $shippingFmt = number_format($shipping, 2);
            $shippingRow = "<tr><td colspan=\"3\" style=\"padding:6px 12px;text-align:right;color:#888;\">Shipping</td><td style=\"padding:6px 12px;text-align:right;\">₹{$shippingFmt}</td></tr>";
        }

        return <<<HTML
        <div style="font-family:'Segoe UI',Arial,sans-serif;max-width:600px;margin:0 auto;color:#333;">
            <div style="background:#881337;padding:20px;text-align:center;border-radius:8px 8px 0 0;">
                <h1 style="color:#e8c36a;margin:0;font-size:20px;">Order Confirmed!</h1>
                <p style="color:#ddd;margin:6px 0 0;font-size:13px;">Shree Patadiya Hanumanji Seva Trust — Temple Store</p>
            </div>

            <div style="padding:24px;background:#fff;border:1px solid #eee;border-top:none;">
                <p style="margin:0 0 16px;">Dear <strong>{$shippingName}</strong>,</p>
                <p style="margin:0 0 20px;color:#555;">Thank you for your order. Here are your order details:</p>

                <table style="width:100%;border-collapse:collapse;margin-bottom:16px;background:#f9f5ef;border-radius:6px;overflow:hidden;">
                    <tr>
                        <td style="padding:10px 14px;color:#888;font-size:12px;">Order No.</td>
                        <td style="padding:10px 14px;font-weight:700;color:#881337;">{$orderNumber}</td>
                        <td style="padding:10px 14px;color:#888;font-size:12px;">Date</td>
                        <td style="padding:10px 14px;font-weight:600;">{$orderDate}</td>
                    </tr>
                </table>

                <table style="width:100%;border-collapse:collapse;margin-bottom:4px;">
                    <thead>
                        <tr style="background:#881337;">
                            <th style="padding:10px 12px;text-align:left;color:#fff;font-size:12px;text-transform:uppercase;">Item</th>
                            <th style="padding:10px 12px;text-align:center;color:#fff;font-size:12px;text-transform:uppercase;">Qty</th>
                            <th style="padding:10px 12px;text-align:right;color:#fff;font-size:12px;text-transform:uppercase;">Price</th>
                            <th style="padding:10px 12px;text-align:right;color:#fff;font-size:12px;text-transform:uppercase;">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        {$itemRows}
                    </tbody>
                    <tfoot>
                        <tr style="border-top:2px solid #881337;">
                            <td colspan="3" style="padding:6px 12px;text-align:right;color:#888;">Subtotal</td>
                            <td style="padding:6px 12px;text-align:right;">₹{$subtotal}</td>
                        </tr>
                        {$shippingRow}
                        <tr style="background:#f9f5ef;">
                            <td colspan="3" style="padding:10px 12px;text-align:right;font-weight:700;font-size:15px;color:#881337;">Total</td>
                            <td style="padding:10px 12px;text-align:right;font-weight:700;font-size:15px;color:#881337;">₹{$total}</td>
                        </tr>
                    </tfoot>
                </table>

                <div style="margin-top:20px;padding:14px;background:#f9f5ef;border-radius:6px;border-left:3px solid #c87533;">
                    <p style="margin:0 0 4px;font-size:12px;color:#888;text-transform:uppercase;letter-spacing:0.5px;">Shipping Address</p>
                    <p style="margin:0;font-weight:600;">{$shippingName}</p>
                    <p style="margin:2px 0;color:#555;">Phone: {$shippingPhone}</p>
                    <p style="margin:2px 0;color:#555;">{$address}</p>
                </div>

                <p style="margin:20px 0 0;color:#555;font-size:13px;">Your invoice is attached to this email as a PDF.</p>
                <p style="margin:16px 0 0;color:#881337;font-weight:600;">May Shree Hanumanji bless you and your family. 🙏</p>
            </div>

            <div style="padding:16px;text-align:center;background:#f5f0ea;border-radius:0 0 8px 8px;border:1px solid #eee;border-top:none;">
                <p style="margin:0;font-size:11px;color:#999;">Shree Patadiya Hanumanji Seva Trust</p>
                <p style="margin:2px 0 0;font-size:11px;color:#bbb;">Antarjal, Gandhidham, Kutch - Gujarat</p>
            </div>
        </div>
        HTML;
    }
}
