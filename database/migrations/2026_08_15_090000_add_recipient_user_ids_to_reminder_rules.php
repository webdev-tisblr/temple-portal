<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let an admin-role reminder target NAMED people inside that role.
 *
 * Until now "Admin role → pujari" meant every active pujari, with no way
 * to say "these two". This column holds the chosen AdminUser ids;
 * NULL/empty keeps the existing meaning — everyone holding the role — so
 * every rule already configured behaves exactly as before.
 *
 * Stored as JSON rather than a pivot table: the list is small, only ever
 * read as a whole alongside its rule, and never queried from the other
 * direction. A pivot would add a table and two joins for nothing.
 */
return new class extends Migration
{
    private const TABLES = ['temple_seva_reminder_rules', 'temple_hall_reminder_rules'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'recipient_user_ids')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t): void {
                $t->json('recipient_user_ids')
                    ->nullable()
                    ->after('recipient_value');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'recipient_user_ids')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t): void {
                $t->dropColumn('recipient_user_ids');
            });
        }
    }
};
