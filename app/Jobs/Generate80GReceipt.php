<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Donation;
use App\Services\ReceiptService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class Generate80GReceipt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public Donation $donation,
    ) {}

    public function handle(ReceiptService $receiptService): void
    {
        $receipt = $receiptService->generateReceipt($this->donation);

        $this->donation->update([
            'receipt_generated' => true,
            'receipt_id' => $receipt->id,
        ]);

        Log::info("80G receipt generated", [
            'donation_id' => $this->donation->id,
            'receipt_number' => $receipt->receipt_number,
        ]);

        // Send receipt via WhatsApp
        $this->donation->loadMissing('devotee');
        $devotee = $this->donation->devotee;

        if ($devotee && $devotee->phone && $receipt->pdf_path) {
            $pdfUrl = url(Storage::url($receipt->pdf_path));
            $filename = "80G_Receipt_{$receipt->receipt_number}.pdf";
            $filename = str_replace('/', '-', $filename);

            SendWhatsAppMessage::dispatch($devotee->phone, 'document', [
                'url' => $pdfUrl,
                'filename' => $filename,
                'caption' => "Thank you for your donation of ₹" . number_format((float) $this->donation->amount) . ". Here is your 80G receipt.",
            ]);

            $receipt->update(['whatsapp_sent_at' => now()]);

            Log::info("80G receipt WhatsApp dispatch", [
                'donation_id' => $this->donation->id,
                'phone' => $devotee->phone,
            ]);
        }
    }
}
