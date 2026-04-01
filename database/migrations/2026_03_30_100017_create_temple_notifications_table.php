<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('title_gu', 255);
            $table->string('title_hi', 255)->nullable();
            $table->string('title_en', 255)->nullable();
            $table->text('body_gu');
            $table->text('body_hi')->nullable();
            $table->text('body_en')->nullable();
            $table->string('image_url', 500)->nullable();
            $table->enum('segment', ['all', 'donors', 'active_users', 'inactive_users', 'birthday_today', 'custom'])->default('all');
            $table->json('custom_filter')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->integer('total_recipients')->default(0);
            $table->integer('delivered_count')->default(0);
            $table->integer('opened_count')->default(0);
            $table->enum('status', ['draft', 'scheduled', 'sending', 'sent', 'failed'])->default('draft');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_notifications');
    }
};
