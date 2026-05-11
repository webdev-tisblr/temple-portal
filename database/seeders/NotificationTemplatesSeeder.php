<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\NotificationTemplate;
use Illuminate\Database\Seeder;

/**
 * Seeds the default email templates for every domain trigger that the
 * platform fires today. Existing flows keep working without admin
 * intervention; admins can later customise the body/subject or enable
 * the WhatsApp / push variants from /admin/notification-templates.
 *
 * Each template is upserted by (key, channel) — re-running the seeder
 * is safe and only creates rows that don't already exist.
 */
class NotificationTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $tpl) {
            NotificationTemplate::updateOrCreate(
                ['key' => $tpl['key'], 'channel' => $tpl['channel']],
                $tpl,
            );
        }
    }

    /** @return list<array<string, mixed>> */
    private function templates(): array
    {
        return [
            // ── HALL BOOKING — email ───────────────────────────────────────
            [
                'key' => 'hall.booking.confirmed',
                'channel' => NotificationTemplate::CHANNEL_EMAIL,
                'label' => 'Hall booking — confirmation email',
                'description' => 'Sent to the booking contact when a hall booking payment is captured.',
                'is_enabled' => true,
                'subject' => 'Hall Booking Confirmation — {{ booking_number }}',
                'body' => $this->hallBookingHtml(),
                'recipient_strategy' => NotificationTemplate::RECIPIENT_CONTEXT_PATH,
                'recipient_value' => 'booking.contact_email',
                'placeholder_map' => [
                    'contact_name' => 'booking.contact_name',
                    'hall_name' => 'booking.hall.name',
                    'booking_date' => 'booking.booking_date',
                    'booking_type' => 'booking.booking_type_label',
                    'purpose' => 'booking.purpose',
                    'amount' => 'booking.total_amount_formatted',
                    'booking_number' => 'booking.booking_number',
                    'trust_name' => 'trust_name',
                ],
            ],

            // ── 80G RECEIPT — email ────────────────────────────────────────
            [
                'key' => 'donation.receipt_80g',
                'channel' => NotificationTemplate::CHANNEL_EMAIL,
                'label' => '80G receipt — donor email',
                'description' => 'Sent with the PDF attached when an 80G receipt is generated.',
                'is_enabled' => true,
                'subject' => '80G Donation Receipt — {{ receipt_number }}',
                'body' => $this->receipt80gHtml(),
                'recipient_strategy' => NotificationTemplate::RECIPIENT_DEVOTEE,
                'recipient_value' => null,
                'placeholder_map' => [
                    'donor_name' => 'devotee.name',
                    'receipt_number' => 'receipt.receipt_number',
                    'amount' => 'receipt.amount_formatted',
                    'fiscal_year' => 'receipt.fiscal_year',
                    'trust_name' => 'trust_name',
                ],
            ],

            // ── STORE ORDER — email ───────────────────────────────────────
            [
                'key' => 'store.order.confirmed',
                'channel' => NotificationTemplate::CHANNEL_EMAIL,
                'label' => 'Store order — confirmation email',
                'description' => 'Sent to the buyer when a store order payment is captured. PDF invoice attached.',
                'is_enabled' => true,
                'subject' => 'Order Confirmation — {{ order_number }}',
                'body' => $this->storeOrderHtml(),
                'recipient_strategy' => NotificationTemplate::RECIPIENT_DEVOTEE,
                'recipient_value' => null,
                'placeholder_map' => [
                    'devotee_name' => 'devotee.name',
                    'order_number' => 'order.order_number',
                    'amount' => 'order.total_amount_formatted',
                    'item_count' => 'order.items_count',
                    'trust_name' => 'trust_name',
                ],
            ],

            // ── CONTACT FORM — admin notification email ───────────────────
            [
                'key' => 'contact.submitted',
                'channel' => NotificationTemplate::CHANNEL_EMAIL,
                'label' => 'Contact form — admin notification',
                'description' => 'Sent to the trust admin when a visitor submits the contact form.',
                'is_enabled' => true,
                'subject' => 'New contact form: {{ subject }}',
                'body' => $this->contactSubmittedHtml(),
                'recipient_strategy' => NotificationTemplate::RECIPIENT_TRUST_ADMIN,
                'recipient_value' => null,
                'placeholder_map' => [
                    'name' => 'submission.name',
                    'phone' => 'submission.phone',
                    'email' => 'submission.email',
                    'subject' => 'submission.subject',
                    'message' => 'submission.message',
                    'trust_name' => 'trust_name',
                ],
            ],

            // ── DONATION CONFIRMED — fast "thanks" email ──────────────────
            [
                'key' => 'donation.confirmed',
                'channel' => NotificationTemplate::CHANNEL_EMAIL,
                'label' => 'Donation — confirmation email',
                'description' => 'Sent the moment a donation payment is captured (the 80G receipt PDF follows separately under donation.receipt_80g).',
                'is_enabled' => true,
                'subject' => 'Thank you for your donation',
                'body' => $this->donationConfirmedHtml(),
                'recipient_strategy' => NotificationTemplate::RECIPIENT_DEVOTEE,
                'recipient_value' => null,
                'placeholder_map' => [
                    'donor_name' => 'devotee.name',
                    'amount' => 'donation.amount',
                    'trust_name' => 'trust_name',
                ],
            ],

            // ── SEVA BOOKING CONFIRMED — email ────────────────────────────
            [
                'key' => 'seva.booking.confirmed',
                'channel' => NotificationTemplate::CHANNEL_EMAIL,
                'label' => 'Seva booking — confirmation email',
                'description' => 'Sent when a seva booking payment is captured.',
                'is_enabled' => true,
                'subject' => 'Seva booking confirmed — {{ seva_name }}',
                'body' => $this->sevaBookingHtml(),
                'recipient_strategy' => NotificationTemplate::RECIPIENT_DEVOTEE,
                'recipient_value' => null,
                'placeholder_map' => [
                    'devotee_name' => 'devotee.name',
                    'seva_name' => 'booking.seva.name_gu',
                    'booking_date' => 'booking.booking_date',
                    'slot_time' => 'booking.slot_time',
                    'amount' => 'booking.total_amount',
                    'trust_name' => 'trust_name',
                ],
            ],

            // ── AUTH OTP — email fallback (off by default) ────────────────
            [
                'key' => 'auth.otp',
                'channel' => NotificationTemplate::CHANNEL_EMAIL,
                'label' => 'Auth OTP — email fallback',
                'description' => 'Email OTP fallback — off by default since most logins use WhatsApp/SMS.',
                'is_enabled' => false,
                'subject' => 'Your OTP — {{ otp }}',
                'body' => '<p>Your OTP is <strong>{{ otp }}</strong>. It expires in {{ expires_in_minutes }} minutes.</p>',
                'recipient_strategy' => NotificationTemplate::RECIPIENT_CONTEXT_PATH,
                'recipient_value' => 'email',
                'placeholder_map' => [
                    'otp' => 'otp',
                    'expires_in_minutes' => 'expires_in_minutes',
                ],
            ],

            // ── DEVOTEE REGISTERED — welcome (off by default) ─────────────
            [
                'key' => 'devotee.registered',
                'channel' => NotificationTemplate::CHANNEL_EMAIL,
                'label' => 'Devotee — welcome email',
                'description' => 'Sent the first time a devotee verifies their phone. Off by default since most devotees don\'t supply an email at signup.',
                'is_enabled' => false,
                'subject' => 'Welcome to {{ trust_name }}',
                'body' => '<p>નમસ્તે {{ name }},</p><p>{{ trust_name }} માં તમારું હાર્દિક સ્વાગત છે. તમે હવે મંદિરની એપ્લિકેશન દ્વારા સેવા, દાન અને દર્શન કરી શકો છો.</p>',
                'recipient_strategy' => NotificationTemplate::RECIPIENT_DEVOTEE,
                'recipient_value' => null,
                'placeholder_map' => [
                    'name' => 'devotee.name',
                    'trust_name' => 'trust_name',
                ],
            ],
        ];
    }

    private function contactSubmittedHtml(): string
    {
        return <<<HTML
        <div style="font-family:'Segoe UI',Arial,sans-serif;max-width:620px;margin:0 auto;color:#333;">
            <div style="background:#881337;padding:18px;border-radius:8px 8px 0 0;">
                <h1 style="color:#e8c36a;margin:0;font-size:18px;">New Contact Form Submission</h1>
                <p style="color:#ddd;margin:4px 0 0;font-size:12px;">{{ trust_name }}</p>
            </div>
            <div style="padding:20px;background:#fff;border:1px solid #eee;border-top:none;border-radius:0 0 8px 8px;">
                <table style="width:100%;border-collapse:collapse;font-size:14px;">
                    <tr><td style="padding:8px 0;color:#888;width:30%;">Name</td><td style="padding:8px 0;font-weight:600;">{{ name }}</td></tr>
                    <tr><td style="padding:8px 0;color:#888;">Phone</td><td style="padding:8px 0;font-weight:600;">{{ phone }}</td></tr>
                    <tr><td style="padding:8px 0;color:#888;">Email</td><td style="padding:8px 0;font-weight:600;">{{ email }}</td></tr>
                    <tr><td style="padding:8px 0;color:#888;">Subject</td><td style="padding:8px 0;font-weight:600;">{{ subject }}</td></tr>
                </table>
                <div style="margin-top:14px;padding:12px;background:#f9f5ef;border-radius:6px;border-left:3px solid #c87533;">
                    <p style="margin:0 0 4px;font-size:12px;color:#888;text-transform:uppercase;letter-spacing:0.5px;">Message</p>
                    <p style="margin:0;color:#333;line-height:1.5;white-space:pre-wrap;">{{ message }}</p>
                </div>
            </div>
        </div>
        HTML;
    }

    private function donationConfirmedHtml(): string
    {
        return <<<HTML
        <div style="font-family:'Segoe UI',Arial,sans-serif;max-width:600px;margin:0 auto;color:#333;">
            <div style="background:#881337;padding:20px;text-align:center;border-radius:8px 8px 0 0;">
                <h1 style="color:#e8c36a;margin:0;font-size:20px;">Thank You for Your Donation</h1>
                <p style="color:#ddd;margin:6px 0 0;font-size:13px;">{{ trust_name }}</p>
            </div>
            <div style="padding:24px;background:#fff;border:1px solid #eee;border-top:none;border-radius:0 0 8px 8px;">
                <p style="margin:0 0 14px;">Dear <strong>{{ donor_name }}</strong>,</p>
                <p style="margin:0 0 14px;color:#555;">Your donation of <strong style="color:#881337;">₹{{ amount }}</strong> has been received successfully. Your 80G receipt will arrive in a separate email shortly.</p>
                <p style="margin:14px 0 0;color:#881337;font-weight:600;">May Shree Hanumanji bless you and your family.</p>
            </div>
        </div>
        HTML;
    }

    private function sevaBookingHtml(): string
    {
        return <<<HTML
        <div style="font-family:'Segoe UI',Arial,sans-serif;max-width:600px;margin:0 auto;color:#333;">
            <div style="background:#881337;padding:20px;text-align:center;border-radius:8px 8px 0 0;">
                <h1 style="color:#e8c36a;margin:0;font-size:20px;">Seva Booking Confirmed</h1>
                <p style="color:#ddd;margin:6px 0 0;font-size:13px;">{{ trust_name }}</p>
            </div>
            <div style="padding:24px;background:#fff;border:1px solid #eee;border-top:none;border-radius:0 0 8px 8px;">
                <p style="margin:0 0 14px;">Dear <strong>{{ devotee_name }}</strong>,</p>
                <p style="margin:0 0 14px;color:#555;">Your seva has been confirmed.</p>
                <table style="width:100%;border-collapse:collapse;margin:8px 0 14px;background:#f9f5ef;border-radius:6px;overflow:hidden;">
                    <tr><td style="padding:10px 14px;color:#888;font-size:12px;">Seva</td><td style="padding:10px 14px;font-weight:700;color:#881337;">{{ seva_name }}</td></tr>
                    <tr><td style="padding:10px 14px;color:#888;font-size:12px;">Date</td><td style="padding:10px 14px;font-weight:600;">{{ booking_date }}</td></tr>
                    <tr><td style="padding:10px 14px;color:#888;font-size:12px;">Slot</td><td style="padding:10px 14px;font-weight:600;">{{ slot_time }}</td></tr>
                    <tr style="background:#f0e8d8;"><td style="padding:12px 14px;font-weight:700;font-size:14px;color:#881337;">Amount</td><td style="padding:12px 14px;font-weight:700;font-size:14px;color:#881337;">₹{{ amount }}</td></tr>
                </table>
                <p style="margin:14px 0 0;color:#881337;font-weight:600;">May Shree Hanumanji bless you and your family.</p>
            </div>
        </div>
        HTML;
    }

    private function hallBookingHtml(): string
    {
        return <<<HTML
        <div style="font-family:'Segoe UI',Arial,sans-serif;max-width:600px;margin:0 auto;color:#333;">
            <div style="background:#881337;padding:20px;text-align:center;border-radius:8px 8px 0 0;">
                <h1 style="color:#e8c36a;margin:0;font-size:20px;">Hall Booking Confirmed!</h1>
                <p style="color:#ddd;margin:6px 0 0;font-size:13px;">{{ trust_name }}</p>
            </div>
            <div style="padding:24px;background:#fff;border:1px solid #eee;border-top:none;">
                <p style="margin:0 0 16px;">Dear <strong>{{ contact_name }}</strong>,</p>
                <p style="margin:0 0 20px;color:#555;">Your hall booking has been confirmed. Here are the details:</p>
                <table style="width:100%;border-collapse:collapse;margin-bottom:16px;background:#f9f5ef;border-radius:6px;overflow:hidden;">
                    <tr><td style="padding:10px 14px;color:#888;font-size:12px;">Booking No.</td><td style="padding:10px 14px;font-weight:700;color:#881337;">{{ booking_number }}</td></tr>
                    <tr><td style="padding:10px 14px;color:#888;font-size:12px;">Hall</td><td style="padding:10px 14px;font-weight:600;">{{ hall_name }}</td></tr>
                    <tr><td style="padding:10px 14px;color:#888;font-size:12px;">Date</td><td style="padding:10px 14px;font-weight:600;">{{ booking_date }}</td></tr>
                    <tr><td style="padding:10px 14px;color:#888;font-size:12px;">Type</td><td style="padding:10px 14px;font-weight:600;">{{ booking_type }}</td></tr>
                    <tr><td style="padding:10px 14px;color:#888;font-size:12px;">Purpose</td><td style="padding:10px 14px;font-weight:600;">{{ purpose }}</td></tr>
                    <tr style="background:#f0e8d8;"><td style="padding:12px 14px;font-weight:700;font-size:14px;color:#881337;">Total</td><td style="padding:12px 14px;font-weight:700;font-size:14px;color:#881337;">₹{{ amount }}</td></tr>
                </table>
                <p style="margin:20px 0 0;color:#555;font-size:13px;">Your invoice is attached to this email as a PDF.</p>
                <p style="margin:16px 0 0;color:#881337;font-weight:600;">May Shree Hanumanji bless you and your family.</p>
            </div>
            <div style="padding:16px;text-align:center;background:#f5f0ea;border-radius:0 0 8px 8px;border:1px solid #eee;border-top:none;">
                <p style="margin:0;font-size:11px;color:#999;">{{ trust_name }}</p>
                <p style="margin:2px 0 0;font-size:11px;color:#bbb;">Antarjal, Gandhidham, Kutch — Gujarat</p>
            </div>
        </div>
        HTML;
    }

    private function receipt80gHtml(): string
    {
        return <<<HTML
        <div style="font-family:'Segoe UI',Arial,sans-serif;max-width:600px;margin:0 auto;color:#333;">
            <div style="background:#881337;padding:20px;text-align:center;border-radius:8px 8px 0 0;">
                <h1 style="color:#e8c36a;margin:0;font-size:20px;">Your 80G Receipt is Ready</h1>
                <p style="color:#ddd;margin:6px 0 0;font-size:13px;">{{ trust_name }}</p>
            </div>
            <div style="padding:24px;background:#fff;border:1px solid #eee;border-top:none;">
                <p style="margin:0 0 16px;">Dear <strong>{{ donor_name }}</strong>,</p>
                <p style="margin:0 0 16px;color:#555;">Thank you for your generous donation. Your 80G receipt is attached to this email.</p>
                <table style="width:100%;border-collapse:collapse;margin:8px 0 16px;background:#f9f5ef;border-radius:6px;overflow:hidden;">
                    <tr><td style="padding:10px 14px;color:#888;font-size:12px;">Receipt No.</td><td style="padding:10px 14px;font-weight:700;color:#881337;">{{ receipt_number }}</td></tr>
                    <tr><td style="padding:10px 14px;color:#888;font-size:12px;">Amount</td><td style="padding:10px 14px;font-weight:700;">₹{{ amount }}</td></tr>
                    <tr><td style="padding:10px 14px;color:#888;font-size:12px;">Fiscal year</td><td style="padding:10px 14px;font-weight:600;">{{ fiscal_year }}</td></tr>
                </table>
                <p style="margin:20px 0 0;color:#555;font-size:13px;">Please retain the attached PDF for your tax records under section 80G of the Income Tax Act.</p>
                <p style="margin:16px 0 0;color:#881337;font-weight:600;">May Shree Hanumanji bless you and your family.</p>
            </div>
            <div style="padding:16px;text-align:center;background:#f5f0ea;border-radius:0 0 8px 8px;border:1px solid #eee;border-top:none;">
                <p style="margin:0;font-size:11px;color:#999;">{{ trust_name }}</p>
            </div>
        </div>
        HTML;
    }

    private function storeOrderHtml(): string
    {
        return <<<HTML
        <div style="font-family:'Segoe UI',Arial,sans-serif;max-width:600px;margin:0 auto;color:#333;">
            <div style="background:#881337;padding:20px;text-align:center;border-radius:8px 8px 0 0;">
                <h1 style="color:#e8c36a;margin:0;font-size:20px;">Order Confirmed</h1>
                <p style="color:#ddd;margin:6px 0 0;font-size:13px;">{{ trust_name }}</p>
            </div>
            <div style="padding:24px;background:#fff;border:1px solid #eee;border-top:none;">
                <p style="margin:0 0 16px;">Dear <strong>{{ devotee_name }}</strong>,</p>
                <p style="margin:0 0 16px;color:#555;">Thank you for shopping at the Mandir Store. Your order has been received.</p>
                <table style="width:100%;border-collapse:collapse;margin:8px 0 16px;background:#f9f5ef;border-radius:6px;overflow:hidden;">
                    <tr><td style="padding:10px 14px;color:#888;font-size:12px;">Order No.</td><td style="padding:10px 14px;font-weight:700;color:#881337;">{{ order_number }}</td></tr>
                    <tr><td style="padding:10px 14px;color:#888;font-size:12px;">Items</td><td style="padding:10px 14px;font-weight:600;">{{ item_count }}</td></tr>
                    <tr style="background:#f0e8d8;"><td style="padding:12px 14px;font-weight:700;font-size:14px;color:#881337;">Total</td><td style="padding:12px 14px;font-weight:700;font-size:14px;color:#881337;">₹{{ amount }}</td></tr>
                </table>
                <p style="margin:20px 0 0;color:#555;font-size:13px;">Your invoice is attached as a PDF. We'll send another note when your order is dispatched.</p>
            </div>
            <div style="padding:16px;text-align:center;background:#f5f0ea;border-radius:0 0 8px 8px;border:1px solid #eee;border-top:none;">
                <p style="margin:0;font-size:11px;color:#999;">{{ trust_name }}</p>
            </div>
        </div>
        HTML;
    }
}
