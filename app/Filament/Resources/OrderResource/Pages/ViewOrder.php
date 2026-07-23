<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrderResource\Pages;

use App\Enums\OrderStatus;
use App\Filament\Resources\OrderResource;
use App\Helpers\NumberToWords;
use App\Models\Product;
use App\Models\SystemSetting;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Log;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('download_invoice')
                ->label('Download Invoice')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('warning')
                // Any paid order can produce an invoice — don't gate on
                // invoice_path: the nightly sweep (invoices:clean-generated)
                // NULLs it after 7 days, which used to make this button
                // vanish and look like "invoices are not being generated".
                ->visible(fn () => ! in_array(
                    $this->record->status?->value ?? (string) $this->record->status,
                    [OrderStatus::PENDING->value, OrderStatus::CANCELLED->value],
                    true,
                ))
                ->action(function () {
                    // Regenerate-on-miss: the stored PDF is a short-lived
                    // cache on R2 (swept after 7 days), so rebuild it when
                    // absent — same self-heal as the web + API endpoints.
                    if (empty($this->record->invoice_path)) {
                        try {
                            app(\App\Services\InvoiceService::class)->generateInvoice($this->record);
                            $this->record->refresh();
                        } catch (\Throwable $e) {
                            Log::error('Admin invoice regen failed', [
                                'order_id' => $this->record->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    if (empty($this->record->invoice_path)) {
                        Notification::make()
                            ->title('Could not generate the invoice')
                            ->body('Check laravel.log — the PDF renderer threw while building this invoice.')
                            ->danger()->send();

                        return null;
                    }

                    // Invoices live on R2, not local disk. Redirect to a
                    // presigned URL — never return raw bytes / a local path
                    // through a Livewire action.
                    return private_file_redirect(
                        $this->record->invoice_path,
                        "Invoice_{$this->record->order_number}.pdf",
                    );
                }),

            Actions\Action::make('packing_slip')
                ->label('Packing Slip')
                ->icon('heroicon-o-truck')
                ->color('success')
                ->action(function () {
                    $order = $this->record->load('items', 'devotee');
                    $trustName = SystemSetting::getValue('trust_name', 'Shree Pataliya Hanumanji Seva Trust');
                    $trustAddress = SystemSetting::getValue('trust_address', 'Antarjal, Gandhidham, Kutch - 370205');
                    $trustPhone = SystemSetting::getValue('trust_phone', '');

                    // mPDF (not DomPDF) — shapes the Gujarati names and
                    // addresses correctly. 102×152mm = 4×6in label.
                    $output = \App\Support\Pdf\GujaratiPdf::render(
                        'invoices.packing-slip',
                        compact('order', 'trustName', 'trustAddress', 'trustPhone'),
                        [
                            'format' => [102, 152],
                            'margin_left' => 5, 'margin_right' => 5,
                            'margin_top' => 5, 'margin_bottom' => 5,
                        ],
                    );
                    return response()->streamDownload(
                        fn () => print($output),
                        "PackingSlip_{$order->order_number}.pdf",
                        ['Content-Type' => 'application/pdf']
                    );
                }),

            Actions\Action::make('update_status')
                ->label('Update Status')
                ->icon('heroicon-o-arrow-path')
                ->color('info')
                ->form([
                    Forms\Components\Select::make('status')
                        ->label('New Status')
                        ->options(collect(OrderStatus::cases())->mapWithKeys(fn ($s) => [$s->value => ucfirst($s->value)]))
                        ->required(),
                ])
                ->action(function (array $data) {
                    $oldStatus = $this->record->status?->value ?? (string) $this->record->status;
                    $newStatus = $data['status'];

                    $this->record->update(['status' => $newStatus]);

                    // Restore stock if we just cancelled a previously-
                    // captured order. Same status set we treat as "stock
                    // was decremented at capture" in
                    // PaymentCaptureService. delivered → cancelled does
                    // NOT restore because physical units already shipped
                    // out; admin should issue a refund through a separate
                    // workflow (return shipping etc.).
                    $restored = false;
                    if ($newStatus === OrderStatus::CANCELLED->value
                        && in_array($oldStatus, [
                            OrderStatus::CONFIRMED->value,
                            OrderStatus::PROCESSING->value,
                            OrderStatus::SHIPPED->value,
                        ], true)) {
                        $restored = $this->restoreStockForOrder();
                    }

                    $msg = 'Order status updated to ' . ucfirst($newStatus);
                    if ($restored) $msg .= ' — stock restored';
                    Notification::make()->title($msg)->success()->send();
                }),

            // Cancel action — clearer affordance than digging into the
            // status dropdown. Confirmation modal so admin doesn't
            // accidentally cancel a live order; stock is restored
            // variant-aware on the same conditions as update_status.
            Actions\Action::make('cancel_order')
                ->label('Cancel Order')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => in_array(
                    $this->record->status?->value ?? (string) $this->record->status,
                    [
                        OrderStatus::PENDING->value,
                        OrderStatus::CONFIRMED->value,
                        OrderStatus::PROCESSING->value,
                        OrderStatus::SHIPPED->value,
                    ],
                    true,
                ))
                ->requiresConfirmation()
                ->modalHeading('Cancel this order?')
                ->modalDescription(fn () => match ($this->record->status?->value ?? (string) $this->record->status) {
                    OrderStatus::PENDING->value =>
                        "This order's payment was never captured, so no stock was decremented. Cancelling just flips its status.",
                    OrderStatus::SHIPPED->value =>
                        "The order has already shipped. Cancelling will restore stock counts BUT the physical units are gone — handle the return separately if a customer is sending the goods back.",
                    default =>
                        "Stock previously decremented for this order will be restored to each product's inventory.",
                })
                ->modalSubmitActionLabel('Yes, cancel order')
                ->action(function () {
                    $oldStatus = $this->record->status?->value ?? (string) $this->record->status;
                    $this->record->update(['status' => OrderStatus::CANCELLED->value]);

                    $restored = false;
                    if (in_array($oldStatus, [
                        OrderStatus::CONFIRMED->value,
                        OrderStatus::PROCESSING->value,
                        OrderStatus::SHIPPED->value,
                    ], true)) {
                        $restored = $this->restoreStockForOrder();
                    }

                    Notification::make()
                        ->title($restored ? 'Order cancelled, stock restored' : 'Order cancelled')
                        ->success()->send();
                }),
        ];
    }

    /**
     * Walk the order's line items and put each item's quantity back
     * into the product's stock — variant-aware via Product helpers.
     * Returns true if at least one item was restored. Failures are
     * logged but never thrown so a missing product reference doesn't
     * block the cancellation from finishing.
     */
    private function restoreStockForOrder(): bool
    {
        $this->record->loadMissing('items');
        $touched = 0;

        foreach ($this->record->items as $item) {
            $product = Product::find($item->product_id);
            if (! $product) {
                Log::warning('Order cancellation: product missing, cannot restore stock', [
                    'order_id' => $this->record->id,
                    'order_item_id' => $item->id,
                    'product_id' => $item->product_id,
                ]);
                continue;
            }

            $ok = $product->has_variants && $item->variant_label
                ? $product->incrementVariantStock($item->variant_label, (int) $item->quantity)
                : (function () use ($product, $item) {
                    $product->incrementStock((int) $item->quantity);
                    return true;
                })();

            if ($ok) {
                $touched++;
            } else {
                Log::warning('Order cancellation: variant label not found, fell back to product stock', [
                    'order_id' => $this->record->id,
                    'product_id' => $product->id,
                    'variant_label' => $item->variant_label,
                ]);
                // Variant gone (admin deleted it after the order was
                // placed). Fall back to the top-level stock so the
                // inventory total at least matches.
                $product->incrementStock((int) $item->quantity);
                $touched++;
            }
        }

        Log::info('Order cancellation: stock restored', [
            'order_id' => $this->record->id,
            'items_touched' => $touched,
        ]);

        return $touched > 0;
    }
}
