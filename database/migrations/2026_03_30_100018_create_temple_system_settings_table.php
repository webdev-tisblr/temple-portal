<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_system_settings', function (Blueprint $table) {
            $table->string('key', 255)->primary();
            $table->text('value');
            $table->string('group', 100)->default('general');
            $table->string('description', 500)->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_system_settings');
    }
};
