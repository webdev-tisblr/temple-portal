<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SystemSetting;
use Razorpay\Api\Api;
use Razorpay\Api\Errors\SignatureVerificationError;

class RazorpayService
{
    private Api $api;

    public function __construct()
    {
        $keyId = SystemSetting::getValue('razorpay_key_id', config('razorpay.key_id'));
        $keySecret = SystemSetting::getValue('razorpay_key_secret', config('razorpay.key_secret'));

        $this->api = new Api($keyId, $keySecret);
    }

    public function createOrder(int $amountInPaise, string $receipt, array $notes = []): object
    {
        return $this->api->order->create([
            'amount' => $amountInPaise,
            'currency' => 'INR',
            'receipt' => $receipt,
            'notes' => $notes,
            // Auto-capture on authorisation so money is never left in the
            // "authorized" limbo (which Razorpay voids after ~5 days) and a
            // `payment.captured` webhook fires — the server-side backup that
            // confirms the booking even if the app's /payments/verify call
            // never reaches us.
            'payment_capture' => 1,
        ]);
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        // Manual HMAC — the old `Api::verifyWebhookSignature()` static
        // call doesn't exist on the SDK's Api class (it lives on Utility),
        // so EVERY webhook delivery 500'd before the signature was ever
        // checked (found 2026-08-04: zero rows in the webhook events
        // table since launch). Razorpay signs the raw body with
        // HMAC-SHA256(hex) using the webhook secret; hash_equals is the
        // timing-safe comparison.
        $secret = SystemSetting::getValue('razorpay_webhook_secret', config('razorpay.webhook_secret'));

        if (! is_string($secret) || $secret === '' || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $payload, $secret), $signature);
    }

    public function verifyPaymentSignature(string $orderId, string $paymentId, string $signature): bool
    {
        try {
            $this->api->utility->verifyPaymentSignature([
                'razorpay_order_id' => $orderId,
                'razorpay_payment_id' => $paymentId,
                'razorpay_signature' => $signature,
            ]);

            return true;
        } catch (SignatureVerificationError) {
            return false;
        }
    }

    public function fetchPayment(string $paymentId): object
    {
        return $this->api->payment->fetch($paymentId);
    }
}
