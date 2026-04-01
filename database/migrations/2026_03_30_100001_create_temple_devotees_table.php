<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_devotees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 255);
            $table->string('phone', 15)->unique();
            $table->string('email', 255)->nullable();
            $table->text('pan_encrypted')->nullable();
            $table->string('pan_last_four', 4)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->default('Gujarat');
            $table->string('pincode', 10)->nullable();
            $table->string('country', 100)->default('India');
            $table->date('date_of_birth')->nullable();
            $table->enum('language', ['gu', 'hi', 'en'])->default('gu');
            $table->string('profile_photo_path', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('phone');
            $table->index('city');
            $table->index('date_of_birth');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_devotees');
    }
};
