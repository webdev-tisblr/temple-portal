<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\NumberToWords;
use App\Models\Order;
use App\Models\SystemSetting;
use App\Support\Pdf\GujaratiPdf;
use Illuminate\Support\Facades\Storage;

class InvoiceService
{
    public function generateInvoice(Order $order): string
    {
        $order->loadMissing('items', 'devotee', 'payment');

        // mPDF (not DomPDF): product names/addresses carry Gujarati,
        // which DomPDF cannot shape.
        $output = GujaratiPdf::render('invoices.store-invoice', [
            'order' => $order,
            'trust_name' => SystemSetting::getValue('trust_name', 'Shree Pataliya Hanumanji Seva Trust'),
            'trust_address' => SystemSetting::getValue('trust_address', 'Antarjal, Gandhidham, Kutch - 370205'),
            'amount_in_words' => NumberToWords::convert((float) $order->total_amount),
        ], ['format' => 'A4', 'watermark' => 'TAX INVOICE']);

        $directory = 'invoices';
        $filename = "{$order->order_number}.pdf";
        $path = "{$directory}/{$filename}";

        Storage::disk('r2_private')->put($path, $output);

        $order->update(['invoice_path' => $path]);

        return $path;
    }
}
