<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_donation_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('title_gu', 500);
            $table->string('title_hi', 500)->nullable();
            $table->string('title_en', 500)->nullable();
            $table->text('description_gu')->nullable();
            $table->text('description_hi')->nullable();
            $table->text('description_en')->nullable();
            $table->decimal('goal_amount', 12, 2);
            $table->decimal('raised_amount', 12, 2)->default(0);
            $table->integer('donor_count')->default(0);
            $table->string('image_path', 500)->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_donation_campaigns');
    }
};
