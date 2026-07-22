<?php

return [
    'shield_resource' => [
        'should_register_navigation' => true,
        'slug' => 'shield/roles',
        // Sort 30 puts Roles & Permissions directly below Admin Users
        // (sort 20) inside the System navigation group (lang/vendor
        // override above).
        'navigation_sort' => 30,
        'navigation_badge' => true,
        'navigation_group' => true,
        'sub_navigation_position' => null,
        'is_globally_searchable' => false,
        'show_model_path' => true,
        // We don't use multi-tenancy. Force false so role rows aren't scoped.
        'is_scoped_to_tenant' => false,
        'cluster' => null,
    ],

    'tenant_model' => null,

    'auth_provider_model' => [
        // AdminUser is the panel's authenticatable, NOT the generic User model.
        'fqcn' => 'App\\Models\\AdminUser',
    ],

    'super_admin' => [
        'enabled' => true,
        'name' => 'super_admin',
        // We register Gate::before in AuthServiceProvider ourselves so super_admin
        // bypass is explicit and grep-able. Shield's auto-intercept stays off.
        'define_via_gate' => false,
        'intercept_gate' => 'before',
    ],

    'panel_user' => [
        // Generates a `panel_user` permission. Required by AdminUser::canAccessPanel
        // so locked-out staff can't load /admin even if is_active is still true.
        'enabled' => true,
        'name' => 'panel_user',
    ],

    'permission_prefixes' => [
        'resource' => [
            'view',
            'view_any',
            'create',
            'update',
            'restore',
            'restore_any',
            'replicate',
            'reorder',
            'delete',
            'delete_any',
            'force_delete',
            'force_delete_any',
        ],

        'page' => 'page',
        'widget' => 'widget',
    ],

    'entities' => [
        'pages' => true,
        'widgets' => true,
        'resources' => true,
        // Enable so we can register cross-cutting perms (approve_refund,
        // view_financial_reports, manage_notification_templates, etc.).
        'custom_permissions' => true,
    ],

    'generator' => [
        'option' => 'policies_and_permissions',
        'policy_directory' => 'Policies',
        'policy_namespace' => 'Policies',
    ],

    'exclude' => [
        'enabled' => true,

        'pages' => [
            // Dashboard stays accessible to anyone who can access the panel.
            'Dashboard',
        ],

        'widgets' => [
            'AccountWidget',
            'FilamentInfoWidget',
        ],

        'resources' => [],
    ],

    'discovery' => [
        'discover_all_resources' => false,
        'discover_all_widgets' => false,
        'discover_all_pages' => false,
    ],

    'register_role_policy' => [
        'enabled' => true,
    ],

];
