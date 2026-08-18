<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-seva preference for the image that seva booking/reminder messages carry
 * (2026-08-18) — the value behind the single `{{ image_url }}` placeholder.
 *
 * A SELECT rather than a boolean toggle. "Use the product image?" cannot
 * express "send no image", and on a seva with no product selection it has to
 * be silently reinterpreted, which is a third state hiding inside a two-state
 * column. The values are:
 *
 *   product — the product the devotee chose, falling back to the seva image
 *   seva    — the seva's own featured image, falling back to the product's
 *   none    — no image (only for templates with no media header)
 *
 * Default 'product' matches what the existing product_image_url placeholder
 * already did for the sevas that offer a choice, and resolves to the seva's
 * own image everywhere else — so no existing seva changes behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_sevas', function (Blueprint $table) {
            $table->string('notification_image_source', 20)
                ->default('product')
                ->after('linked_products');
        });
    }

    public function down(): void
    {
        Schema::table('temple_sevas', function (Blueprint $table) {
            $table->dropColumn('notification_image_source');
        });
    }
};
