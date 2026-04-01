<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_device_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuid('devotee_id');
            $table->text('token');
            $table->enum('platform', ['android', 'ios']);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->foreign('devotee_id')->references('id')->on('temple_devotees')->onDelete('cascade');
            $table->index('devotee_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_device_tokens');
    }
};
