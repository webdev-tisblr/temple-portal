<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add SMS support to the notification_templates schema:
 *  • channel enum gains 'sms'
 *  • new sms_template_id column (the MSG91 template id the row points
 *    at). Variable ordering for the template is reused from the
 *    existing placeholder_map column — keys named var1, var2, …
 *    determine the order in which template variables are filled.
 */
return new class extends Migration {
    public function up(): void
    {
        // MySQL ENUM is the only place Laravel's fluent Schema can't help
        // — fall back to raw SQL. Tested on MySQL 8 and MariaDB 10.5+.
        DB::statement(
            "ALTER TABLE temple_notification_templates"
            . " MODIFY COLUMN channel ENUM('email', 'whatsapp', 'push', 'sms') NOT NULL"
        );

        Schema::table('temple_notification_templates', function (Blueprint $table) {
            $table->string('sms_template_id')
                ->nullable()
                ->after('wa_components')
                ->comment('MSG91 template id (returned by the dashboard once the DLT-approved template is synced)');
        });
    }

    public function down(): void
    {
        Schema::table('temple_notification_templates', function (Blueprint $table) {
            $table->dropColumn('sms_template_id');
        });

        DB::statement(
            "ALTER TABLE temple_notification_templates"
            . " MODIFY COLUMN channel ENUM('email', 'whatsapp', 'push') NOT NULL"
        );
    }
};
