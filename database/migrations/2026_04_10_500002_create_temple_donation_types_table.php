<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_donation_types', function (Blueprint $table) {
            $table->id();
            $table->string('name_gu', 255);
            $table->string('name_hi', 255);
            $table->string('name_en', 255);
            $table->string('slug', 255)->unique();
            $table->text('description')->nullable();
            $table->json('extra_fields')->nullable();
            $table->json('greeting_card_config')->nullable();
            $table->string('greeting_card_template', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order']);
        });

        // Seed initial donation types from existing enum
        DB::table('temple_donation_types')->insert([
            [
                'name_gu' => 'સામાન્ય દાન',
                'name_hi' => 'सामान्य दान',
                'name_en' => 'General Donation',
                'slug' => 'general',
                'description' => null,
                'extra_fields' => null,
                'greeting_card_config' => null,
                'greeting_card_template' => null,
                'is_active' => true,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name_gu' => 'અન્નદાન',
                'name_hi' => 'अन्नदान',
                'name_en' => 'Annadan',
                'slug' => 'annadan',
                'description' => null,
                'extra_fields' => null,
                'greeting_card_config' => null,
                'greeting_card_template' => null,
                'is_active' => true,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name_gu' => 'નિર્માણ / જીર્ણોદ્ધાર',
                'name_hi' => 'निर्माण / जीर्णोद्धार',
                'name_en' => 'Construction',
                'slug' => 'construction',
                'description' => null,
                'extra_fields' => null,
                'greeting_card_config' => null,
                'greeting_card_template' => null,
                'is_active' => true,
                'sort_order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name_gu' => 'ઉત્સવ / તહેવાર',
                'name_hi' => 'उत्सव / त्योहार',
                'name_en' => 'Festival',
                'slug' => 'festival',
                'description' => null,
                'extra_fields' => null,
                'greeting_card_config' => null,
                'greeting_card_template' => null,
                'is_active' => true,
                'sort_order' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_donation_types');
    }
};
