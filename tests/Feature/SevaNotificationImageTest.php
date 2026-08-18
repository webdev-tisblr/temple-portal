<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Seva;
use App\Models\SevaBooking;
use App\Models\SystemSetting;
use App\Services\Notifications\SevaBookingContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `{{ image_url }}` — one WhatsApp template serves every seva, so all the
 * branching happens server-side (2026-08-18).
 *
 * The contract these lock down: the per-seva setting is a PREFERENCE at the
 * head of a fallback chain, never a hard branch, and the chain only ends empty
 * when the seva deliberately opts out. Anything else is a message Meta rejects
 * outright for having an image header with no link.
 */
class SevaNotificationImageTest extends TestCase
{
    use RefreshDatabase;

    private function booking(?string $sevaImage, ?string $productImage, string $source): SevaBooking
    {
        $seva = new Seva(['name_gu' => 'સેવા', 'image_path' => $sevaImage]);
        $seva->notification_image_source = $source;

        $booking = new SevaBooking;
        $booking->setRelation('seva', $seva);
        $booking->setRelation('selectedProduct', $productImage === null
            ? null
            : new Product(['name_gu' => 'પ્રસાદ', 'price' => 101, 'image_path' => $productImage]));

        return $booking;
    }

    public function test_product_source_prefers_the_chosen_product(): void
    {
        $url = SevaBookingContext::resolveImageUrl(
            $this->booking('sevas/s.jpg', 'products/p.jpg', Seva::IMAGE_SOURCE_PRODUCT)
        );

        $this->assertStringContainsString('products/p.jpg', $url);
    }

    public function test_seva_source_wins_even_when_a_product_was_chosen(): void
    {
        $url = SevaBookingContext::resolveImageUrl(
            $this->booking('sevas/s.jpg', 'products/p.jpg', Seva::IMAGE_SOURCE_SEVA)
        );

        $this->assertStringContainsString('sevas/s.jpg', $url);
    }

    public function test_a_seva_with_no_product_choice_falls_back_to_its_own_image(): void
    {
        $url = SevaBookingContext::resolveImageUrl(
            $this->booking('sevas/s.jpg', null, Seva::IMAGE_SOURCE_PRODUCT)
        );

        $this->assertStringContainsString('sevas/s.jpg', $url);
    }

    public function test_the_chain_reverses_when_the_preferred_source_has_no_image(): void
    {
        // Preference is the seva's own image, but nobody uploaded one — the
        // product's image is better than no image at all.
        $url = SevaBookingContext::resolveImageUrl(
            $this->booking(null, 'products/p.jpg', Seva::IMAGE_SOURCE_SEVA)
        );

        $this->assertStringContainsString('products/p.jpg', $url);
    }

    public function test_the_trust_wide_default_catches_a_seva_with_nothing(): void
    {
        SystemSetting::updateOrCreate(
            ['key' => 'notification_default_image'],
            ['value' => 'settings/default.jpg', 'group' => 'general']
        );

        $url = SevaBookingContext::resolveImageUrl(
            $this->booking(null, null, Seva::IMAGE_SOURCE_PRODUCT)
        );

        $this->assertStringContainsString('settings/default.jpg', $url);
    }

    public function test_none_opts_out_entirely(): void
    {
        SystemSetting::updateOrCreate(
            ['key' => 'notification_default_image'],
            ['value' => 'settings/default.jpg', 'group' => 'general']
        );

        $this->assertSame('', SevaBookingContext::resolveImageUrl(
            $this->booking('sevas/s.jpg', 'products/p.jpg', Seva::IMAGE_SOURCE_NONE)
        ));
    }

    public function test_a_seva_saved_before_this_column_existed_behaves_as_product(): void
    {
        // Empty string rather than null — the column has a default, but a
        // direct SQL insert or an import can still leave it blank.
        $url = SevaBookingContext::resolveImageUrl(
            $this->booking('sevas/s.jpg', 'products/p.jpg', '')
        );

        $this->assertStringContainsString('products/p.jpg', $url);
    }

    public function test_the_unresolved_sources_stay_available_to_templates(): void
    {
        $values = SevaBookingContext::imageValues(
            $this->booking('sevas/s.jpg', 'products/p.jpg', Seva::IMAGE_SOURCE_SEVA)
        );

        $this->assertStringContainsString('sevas/s.jpg', $values['seva_image_url']);
        $this->assertStringContainsString('products/p.jpg', $values['product_image_url']);
        $this->assertStringContainsString('sevas/s.jpg', $values['image_url']);
    }

    public function test_product_keys_are_present_and_blank_without_a_product(): void
    {
        $values = SevaBookingContext::values($this->booking('sevas/s.jpg', null, Seva::IMAGE_SOURCE_PRODUCT));

        foreach (['product_name_gu', 'product_name_hi', 'product_name_en', 'product_price', 'product_image_url'] as $key) {
            $this->assertSame('', $values[$key], "{$key} must render blank, not the literal token");
        }
    }
}
