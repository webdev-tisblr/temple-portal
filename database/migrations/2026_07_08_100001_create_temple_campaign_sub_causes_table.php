<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_campaign_sub_causes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('temple_donation_campaigns')->cascadeOnDelete();
            $table->string('title_gu', 255);
            $table->string('title_hi', 255)->nullable();
            $table->string('title_en', 255)->nullable();
            $table->decimal('goal_amount', 12, 2)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['campaign_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_campaign_sub_causes');
    }
};
