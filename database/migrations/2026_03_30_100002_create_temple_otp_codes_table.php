<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 15);
            $table->string('code', 6);
            $table->enum('purpose', ['login', 'verify_phone', 'verify_pan'])->default('login');
            $table->integer('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['phone', 'code']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_otp_codes');
    }
};
