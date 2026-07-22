<?php

/*
|--------------------------------------------------------------------------
| Vendor override for bezhansalleh/filament-shield translations.
|--------------------------------------------------------------------------
|
| Laravel loads namespaced translations from
| lang/vendor/{package}/{locale}/{file}.php in preference to the
| package's bundled file. We override the navigation group + role icon
| so Roles sits inside the "System" group right under Admin Users in
| the admin sidebar (matching the AdminUserResource group) and uses a
| key icon that's visually distinct from AdminUser's people icon —
| previously both sat under "Filament Shield" with the same shield
| glyph, which was confusing.
|
| Every other Shield string is copied through verbatim so we don't
| lose any labels — Laravel REPLACES the entire file for namespaced
| translations rather than merging keys.
*/

return [
    'column.name' => 'Name',
    'column.guard_name' => 'Guard Name',
    'column.team' => 'Team',
    'column.roles' => 'Roles',
    'column.permissions' => 'Permissions',
    'column.updated_at' => 'Updated At',

    'field.name' => 'Name',
    'field.guard_name' => 'Guard Name',
    'field.permissions' => 'Permissions',
    'field.team' => 'Team',
    'field.team.placeholder' => 'Select a team ...',
    'field.select_all.name' => 'Select All',
    'field.select_all.message' => 'Enables/Disables all Permissions for this role',

    // ── Local overrides ──────────────────────────────────────────
    'nav.group' => 'System',
    'nav.role.label' => 'Roles & Permissions',
    'nav.role.icon' => 'heroicon-o-key',
    // ─────────────────────────────────────────────────────────────

    'resource.label.role' => 'Role',
    'resource.label.roles' => 'Roles',

    'section' => 'Entities',
    'resources' => 'Resources',
    'widgets' => 'Widgets',
    'pages' => 'Pages',
    'custom' => 'Custom Permissions',

    'forbidden' => 'You do not have permission to access',

    'resource_permission_prefixes_labels' => [
        'view' => 'View',
        'view_any' => 'View Any',
        'create' => 'Create',
        'update' => 'Update',
        'delete' => 'Delete',
        'delete_any' => 'Delete Any',
        'force_delete' => 'Force Delete',
        'force_delete_any' => 'Force Delete Any',
        'restore' => 'Restore',
        'reorder' => 'Reorder',
        'restore_any' => 'Restore Any',
        'replicate' => 'Replicate',
    ],
];
