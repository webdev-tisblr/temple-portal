<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\Seva;
use App\Models\SevaBooking;
use App\Models\SystemSetting;

/**
 * The context values that describe WHAT was booked — the chosen product and
 * the image the message should carry.
 *
 * Lives here rather than in either caller because both the confirmation
 * (GenerateSevaReceipt) and the reminder (DispatchSevaReminders) need exactly
 * the same keys. It started as a private method on the reminder command
 * (2026-08-17) and the confirmation had none of it, which is precisely the
 * drift this class exists to stop.
 */
final class SevaBookingContext
{
    /**
     * Every product + image key, for merging into a seva notification context.
     *
     * @return array<string, string>
     */
    public static function values(SevaBooking $booking): array
    {
        return array_merge(self::productValues($booking), self::imageValues($booking));
    }

    /**
     * Context values describing the product the devotee chose, for sevas that
     * offer a product selection (2026-08-17).
     *
     * The three `_gu/_hi/_en` name keys follow the same convention as
     * `booking.seva_name`: the placeholder map points at `product_name_gu`
     * and NotificationContext::getLocalized() swaps in the reader's language
     * automatically. The variant label is appended to the name because a bare
     * "Chundadi" tells a pujari nothing when the booking was for the large one.
     *
     * Every key is present (empty string) even with no product, so a template
     * that references them on a seva without products renders blanks rather
     * than the literal {{ token }}.
     *
     * @return array<string, string>
     */
    public static function productValues(SevaBooking $booking): array
    {
        $product = $booking->selectedProduct;
        $blank = [
            'product_name_gu' => '',
            'product_name_hi' => '',
            'product_name_en' => '',
            'product_price' => '',
            'product_image_url' => '',
        ];

        if ($product === null) {
            return $blank;
        }

        $variant = trim((string) $booking->selected_variant_label);
        $suffix = $variant === '' ? '' : " — {$variant}";

        // The variant's own price when one was chosen — that is what the
        // devotee actually paid, not the product's base price.
        $price = $variant !== '' ? $product->getVariantPrice($variant) : null;
        $price ??= (float) $product->price;

        return [
            'product_name_gu' => ($product->name_gu ?? '').$suffix,
            'product_name_hi' => ($product->name_hi ?? '') === '' ? '' : $product->name_hi.$suffix,
            'product_name_en' => ($product->name_en ?? '') === '' ? '' : $product->name_en.$suffix,
            // inr_money matches the `amount` placeholder elsewhere: ₹ sign,
            // Indian digit grouping, paise only when non-zero.
            'product_price' => $price > 0 ? inr_money($price) : '',
            // Absolute CDN URL — a bare image_path is useless as a WhatsApp
            // header link or an <img> src.
            'product_image_url' => image_url($product->image_path) ?? '',
        ];
    }

    /**
     * The image keys (2026-08-18).
     *
     * `image_url` is the one a template maps to a WhatsApp IMAGE header. One
     * template serves every seva, so all the branching happens here: the seva's
     * notification_image_source states a PREFERENCE and this resolves it down
     * a fallback chain that ends at a trust-wide default image.
     *
     * Never returning an empty URL is the whole point. An image header whose
     * link resolves to '' is handed to Meta verbatim
     * (WhatsAppNotificationDriver::buildComponents) and Meta rejects the ENTIRE
     * message — so one seva missing its featured image would silently kill its
     * own confirmations while every other seva kept working.
     *
     * `seva_image_url` / `product_image_url` are the unresolved sources, for a
     * template that wants one specific image regardless of the seva's setting.
     *
     * @return array<string, string>
     */
    public static function imageValues(SevaBooking $booking): array
    {
        $seva = $booking->seva;
        $product = $booking->selectedProduct;

        return [
            'image_url' => self::resolveImageUrl($booking),
            'seva_image_url' => image_url($seva?->image_path) ?? '',
            'product_image_url' => image_url($product?->image_path) ?? '',
        ];
    }

    /**
     * First non-empty image down the preference chain, or '' when the seva
     * opts out of images entirely.
     */
    public static function resolveImageUrl(SevaBooking $booking): string
    {
        $seva = $booking->seva;
        $source = $seva?->notification_image_source ?: Seva::IMAGE_SOURCE_PRODUCT;

        if ($source === Seva::IMAGE_SOURCE_NONE) {
            return '';
        }

        $sevaPath = $seva?->image_path;
        $productPath = $booking->selectedProduct?->image_path;

        $candidates = $source === Seva::IMAGE_SOURCE_SEVA
            ? [$sevaPath, $productPath]
            : [$productPath, $sevaPath];

        // Last resort, trust-wide: set in System Settings → General. Without
        // it the chain can still bottom out empty on a seva with no featured
        // image whose chosen product has no image either.
        $candidates[] = SystemSetting::getValue('notification_default_image');

        foreach ($candidates as $path) {
            $url = image_url($path ?: null);
            if (! empty($url)) {
                return $url;
            }
        }

        return '';
    }
}
