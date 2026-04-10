<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\NumberToWords;
use App\Models\Order;
use App\Models\SystemSetting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class InvoiceService
{
    public function generateInvoice(Order $order): string
    {
        $order->loadMissing('items', 'devotee', 'payment');

        $pdf = Pdf::loadView('invoices.store-invoice', [
            'order' => $order,
            'trust_name' => SystemSetting::getValue('trust_name', 'Shree Pataliya Hanumanji Seva Trust'),
            'trust_address' => SystemSetting::getValue('trust_address', 'Antarjal, Gandhidham, Kutch - 370205'),
            'amount_in_words' => NumberToWords::convert((float) $order->total_amount),
        ]);
        $pdf->setPaper('a4');

        $directory = 'invoices';
        $filename = "{$order->order_number}.pdf";
        $path = "{$directory}/{$filename}";

        Storage::disk('local')->makeDirectory($directory);
        Storage::disk('local')->put($path, $pdf->output());

        $order->update(['invoice_path' => $path]);

        return $path;
    }
}
