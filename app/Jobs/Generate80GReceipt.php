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
use Illuminate\Support\Facades\Mail;
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

        // Generate greeting card if donation type has a template
        try {
            $cardPath = app(\App\Services\GreetingCardService::class)->generate($this->donation);
            if ($cardPath) {
                Log::info("Greeting card generated", ['donation_id' => $this->donation->id]);
            }
        } catch (\Exception $e) {
            Log::error("Greeting card generation failed", ['error' => $e->getMessage()]);
        }

        $this->donation->loadMissing('devotee');
        $devotee = $this->donation->devotee;

        if (! $devotee) {
            return;
        }

        // Send receipt via email
        if ($devotee->email && $receipt->pdf_path) {
            $this->sendReceiptEmail($devotee, $receipt);
        }

        // Send receipt via WhatsApp
        if ($devotee->phone && $receipt->pdf_path) {
            $pdfUrl = url(Storage::url($receipt->pdf_path));
            $filename = str_replace('/', '-', "80G_Receipt_{$receipt->receipt_number}.pdf");

            SendWhatsAppMessage::dispatch($devotee->phone, 'document', [
                'url' => $pdfUrl,
                'filename' => $filename,
                'caption' => "Thank you for your donation of ₹" . number_format((float) $this->donation->amount) . ". Here is your 80G receipt.",
            ]);

            $receipt->update(['whatsapp_sent_at' => now()]);

            // Send greeting card via WhatsApp if configured
            $cardConfig = $this->donation->donationType?->greeting_card_config ?? [];
            if (($cardConfig['send_via_whatsapp'] ?? true) && $this->donation->greeting_card_path) {
                $cardUrl = url('/donate/greeting-card/' . $this->donation->id);
                SendWhatsAppMessage::dispatch($devotee->phone, 'document', [
                    'url' => $cardUrl,
                    'filename' => 'Greeting_Card.png',
                    'caption' => 'Here is your personalised greeting card from Shree Pataliya Hanumanji Seva Trust.',
                ]);
            }
        }
    }

    private function sendReceiptEmail($devotee, $receipt): void
    {
        try {
            $pdfPath = Storage::disk('local')->path($receipt->pdf_path);
            $amount = number_format((float) $this->donation->amount, 2);
            $receiptNumber = $receipt->receipt_number;

            $this->donation->loadMissing('sevaBooking.seva.assignee');
            $booking = $this->donation->sevaBooking;

            $subject = $booking
                ? "Seva Booking Confirmed & 80G Receipt — {$receiptNumber}"
                : "80G Donation Receipt — {$receiptNumber}";

            $html = $this->buildReceiptEmailHtml($devotee, $receipt, $booking, $amount);

            Mail::html($html, function ($message) use ($devotee, $pdfPath, $receiptNumber, $subject) {
                $message->to($devotee->email, $devotee->name)
                    ->subject($subject)
                    ->attach($pdfPath, [
                        'as' => str_replace('/', '-', "80G_Receipt_{$receiptNumber}.pdf"),
                        'mime' => 'application/pdf',
                    ]);

                // Attach greeting card if generated
                if ($this->donation->greeting_card_path) {
                    $cardPath = Storage::disk('local')->path($this->donation->greeting_card_path);
                    if (file_exists($cardPath)) {
                        $message->attach($cardPath, [
                            'as' => 'Greeting_Card.png',
                            'mime' => 'image/png',
                        ]);
                    }
                }
            });

            $receipt->update(['emailed_at' => now()]);

            Log::info("80G receipt emailed", [
                'donation_id' => $this->donation->id,
                'email' => $devotee->email,
            ]);
        } catch (\Exception $e) {
            Log::error("80G receipt email failed", [
                'donation_id' => $this->donation->id,
                'email' => $devotee->email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildReceiptEmailHtml($devotee, $receipt, $booking, string $amount): string
    {
        $name = e($devotee->name);
        $receiptNo = e($receipt->receipt_number);

        $bookingHtml = '';
        if ($booking && $booking->seva) {
            $sevaName = e($booking->seva->name_en);
            $date = $booking->booking_date->format('d M Y');
            $time = $booking->slot_time ? \Carbon\Carbon::parse($booking->slot_time)->format('h:i A') : null;

            $rows = "<tr><td style=\"padding:6px 12px;color:#888;width:40%;\">Seva</td><td style=\"padding:6px 12px;font-weight:600;\">{$sevaName}</td></tr>"
                . "<tr style=\"background:#f9f5ef;\"><td style=\"padding:6px 12px;color:#888;\">Date</td><td style=\"padding:6px 12px;\">{$date}</td></tr>";

            if ($time) {
                $rows .= "<tr><td style=\"padding:6px 12px;color:#888;\">Time</td><td style=\"padding:6px 12px;\">{$time}</td></tr>";
            }
            if ($booking->devotee_name_for_seva) {
                $rows .= "<tr style=\"background:#f9f5ef;\"><td style=\"padding:6px 12px;color:#888;\">Name for Seva</td><td style=\"padding:6px 12px;\">" . e($booking->devotee_name_for_seva) . "</td></tr>";
            }
            if ($booking->gotra) {
                $rows .= "<tr><td style=\"padding:6px 12px;color:#888;\">Gotra</td><td style=\"padding:6px 12px;\">" . e($booking->gotra) . "</td></tr>";
            }
            if ($booking->sankalp) {
                $rows .= "<tr style=\"background:#f9f5ef;\"><td style=\"padding:6px 12px;color:#888;\">Sankalp</td><td style=\"padding:6px 12px;\">" . e($booking->sankalp) . "</td></tr>";
            }
            $rows .= "<tr style=\"background:#f9f5ef;\"><td style=\"padding:6px 12px;color:#888;\">Amount</td><td style=\"padding:6px 12px;font-weight:700;color:#881337;font-size:16px;\">₹{$amount}</td></tr>";

            $bookingHtml = "<table style=\"width:100%;border-collapse:collapse;margin:16px 0;border:1px solid #eee;border-radius:6px;overflow:hidden;\">{$rows}</table>";

            // Assignee contact
            $assignee = $booking->seva->assignee;
            if ($assignee) {
                $aName = e($assignee->name);
                $bookingHtml .= "<div style=\"padding:12px 14px;background:#f9f5ef;border-radius:6px;border-left:3px solid #c87533;margin-bottom:16px;\">"
                    . "<p style=\"margin:0 0 4px;font-size:12px;color:#888;text-transform:uppercase;letter-spacing:0.5px;\">Seva Contact</p>"
                    . "<p style=\"margin:0;font-weight:600;\">{$aName}</p>";
                if ($assignee->phone) {
                    $bookingHtml .= "<p style=\"margin:2px 0;color:#555;\">Phone: +91 " . e($assignee->phone) . "</p>";
                }
                if ($assignee->email) {
                    $bookingHtml .= "<p style=\"margin:2px 0;color:#555;\">Email: " . e($assignee->email) . "</p>";
                }
                $bookingHtml .= "</div>";
            }
        }

        $donationRow = '';
        if (! $booking) {
            $type = ucfirst($this->donation->getRawOriginal('donation_type'));
            $purpose = e($this->donation->purpose ?? '—');
            $donationRow = "<table style=\"width:100%;border-collapse:collapse;margin:16px 0;border:1px solid #eee;border-radius:6px;overflow:hidden;\">"
                . "<tr><td style=\"padding:6px 12px;color:#888;width:40%;\">Type</td><td style=\"padding:6px 12px;\">{$type}</td></tr>"
                . "<tr style=\"background:#f9f5ef;\"><td style=\"padding:6px 12px;color:#888;\">Purpose</td><td style=\"padding:6px 12px;\">{$purpose}</td></tr>"
                . "<tr><td style=\"padding:6px 12px;color:#888;\">Amount</td><td style=\"padding:6px 12px;font-weight:700;color:#881337;font-size:16px;\">₹{$amount}</td></tr>"
                . "</table>";
        }

        return <<<HTML
        <div style="font-family:'Segoe UI',Arial,sans-serif;max-width:600px;margin:0 auto;color:#333;">
            <div style="background:#881337;padding:20px;text-align:center;border-radius:8px 8px 0 0;">
                <h1 style="color:#e8c36a;margin:0;font-size:20px;">Thank You for Your Donation!</h1>
                <p style="color:#ddd;margin:6px 0 0;font-size:13px;">Shree Pataliya Hanumanji Seva Trust</p>
            </div>
            <div style="padding:24px;background:#fff;border:1px solid #eee;border-top:none;">
                <p style="margin:0 0 16px;">Dear <strong>{$name}</strong>,</p>
                <p style="margin:0 0 8px;color:#555;">Thank you for your generous donation of <strong style="color:#881337;">₹{$amount}</strong> to Shree Pataliya Hanumanji Seva Trust.</p>
                {$bookingHtml}
                {$donationRow}
                <div style="padding:12px;background:#f9f5ef;border-radius:6px;text-align:center;margin:16px 0;">
                    <p style="margin:0;font-size:13px;color:#555;">80G Receipt: <strong style="color:#881337;">{$receiptNo}</strong></p>
                    <p style="margin:4px 0 0;font-size:11px;color:#888;">Attached as PDF. Use for income tax deduction under Section 80G.</p>
                </div>
                <p style="margin:16px 0 0;color:#881337;font-weight:600;">May Shree Hanumanji bless you and your family. 🙏</p>
            </div>
            <div style="padding:16px;text-align:center;background:#f5f0ea;border-radius:0 0 8px 8px;border:1px solid #eee;border-top:none;">
                <p style="margin:0;font-size:11px;color:#999;">Shree Pataliya Hanumanji Seva Trust</p>
                <p style="margin:2px 0 0;font-size:11px;color:#bbb;">Antarjal, Gandhidham, Kutch - Gujarat</p>
            </div>
        </div>
        HTML;
    }
}
