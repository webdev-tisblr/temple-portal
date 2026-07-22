<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('temple_blog_posts');
        Schema::dropIfExists('temple_announcements');

        // Purge the modules' Shield permissions. Exact names only — a LIKE
        // pattern would also match the unrelated custom send_announcement
        // permission, which stays. Pivot rows cascade on delete.
        $actions = ['view_any', 'view', 'create', 'update', 'delete', 'delete_any',
            'force_delete', 'force_delete_any', 'restore', 'restore_any',
            'replicate', 'reorder'];
        $names = [];
        foreach (['announcement', 'blog::post'] as $slug) {
            foreach ($actions as $a) {
                $names[] = "{$a}_{$slug}";
            }
        }
        DB::table('permissions')->where('guard_name', 'admin')->whereIn('name', $names)->delete();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Irreversible removal — restore from the pre-migration DB snapshot.
    }
};
