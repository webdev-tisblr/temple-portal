<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_hall_bookings', function (Blueprint $table) {
            $table->string('contact_email', 255)->nullable()->after('contact_phone');
            $table->string('aadhaar_number', 12)->nullable()->after('contact_email');
            $table->text('contact_address')->nullable()->after('aadhaar_number');
            $table->string('invoice_path', 500)->nullable()->after('admin_notes');
        });
    }

    public function down(): void
    {
        Schema::table('temple_hall_bookings', function (Blueprint $table) {
            $table->dropColumn(['contact_email', 'aadhaar_number', 'contact_address', 'invoice_path']);
        });
    }
};
