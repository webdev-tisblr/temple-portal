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
        Schema::table('temple_sevas', function (Blueprint $table) {
            $table->unsignedBigInteger('assignee_id')->nullable()->after('sort_order');
            $table->json('notification_config')->nullable()->after('slot_config');

            $table->foreign('assignee_id')->references('id')->on('temple_admin_users')->nullOnDelete();
            $table->index('assignee_id');
        });

        // Backfill: assign all existing sevas to the first admin user
        $firstAdmin = DB::table('temple_admin_users')->first();
        if ($firstAdmin) {
            DB::table('temple_sevas')->whereNull('assignee_id')->update(['assignee_id' => $firstAdmin->id]);
        }
    }

    public function down(): void
    {
        Schema::table('temple_sevas', function (Blueprint $table) {
            $table->dropForeign(['assignee_id']);
            $table->dropColumn(['assignee_id', 'notification_config']);
        });
    }
};
