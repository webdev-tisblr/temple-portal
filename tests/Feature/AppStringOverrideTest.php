<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\AppStringOverrideResource\Pages\CreateAppStringOverride;
use App\Filament\Resources\AppStringOverrideResource\Pages\EditAppStringOverride;
use App\Filament\Resources\AppStringOverrideResource\Pages\ListAppStringOverrides;
use App\Models\AdminUser;
use App\Models\AppStringOverride;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * "App Text Fixes" (admin) end-to-end: create → edit → save again →
 * remove, plus the API the phones read.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * The reported complaint was "entries stack up with no way to delete, and
 * the panel doesn't reflect or save updated values". Delete actions and
 * the unique (key, locale) index were already in place; what was actually
 * broken lived in the reference snapshot (resources/app-l10n/*.json), a
 * hand-copied mirror of the app's assets/l10n/*.json that had fallen
 * dozens of keys behind. Two concrete defects fell out of that, and both
 * are pinned below (each one fails against the pre-fix resource):
 *
 *  - test_edit_form_shows_and_resaves_a_key_missing_from_the_snapshot —
 *    the key picker was built ONLY from the snapshot, so a row whose key
 *    the snapshot didn't know opened with a BLANK "App string" field.
 *  - test_current_text_panel_shows_the_saved_override_not_only_bundled_text
 *    — the "current text" panel rendered bundled text only, identically
 *    before and after saving, so an edit looked like it hadn't taken.
 *
 * MySQL-only project: needs a test database (phpunit.xml).
 */
class AppStringOverrideTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->admin = $this->adminWithFullOverrideAccess();
        $this->actingAs($this->admin, 'admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * A non-super admin holding exactly the app::string::override grants,
     * so these tests exercise the policy another agent added rather than
     * riding the super_admin Gate::before bypass.
     */
    private function adminWithFullOverrideAccess(): AdminUser
    {
        $suffix = Str::lower(Str::random(8));

        $role = Role::create(['name' => "l10n_role_{$suffix}", 'guard_name' => 'admin']);
        $role->syncPermissions([
            'panel_user',
            'view_any_app::string::override',
            'view_app::string::override',
            'create_app::string::override',
            'update_app::string::override',
            'delete_app::string::override',
            'delete_any_app::string::override',
        ]);

        $user = AdminUser::create([
            'name' => "L10n Admin {$suffix}",
            'email' => "l10n-{$suffix}@example.test",
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /** A key that genuinely exists in the current snapshot. */
    private function snapshotKey(): string
    {
        $snapshot = json_decode((string) file_get_contents(resource_path('app-l10n/en.json')), true);

        $this->assertIsArray($snapshot);
        $this->assertArrayHasKey('home.book_seva', $snapshot, 'Snapshot lost a key this test relies on.');

        return 'home.book_seva';
    }

    // ---------------------------------------------------------------
    // The full admin round trip the user described.
    // ---------------------------------------------------------------

    public function test_admin_can_create_edit_resave_and_remove_a_text_fix(): void
    {
        $key = $this->snapshotKey();

        // CREATE
        Livewire::test(CreateAppStringOverride::class)
            ->fillForm([
                'key' => $key,
                'locale' => 'gu',
                'value' => 'પહેલો સુધારો',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $override = AppStringOverride::where('key', $key)->where('locale', 'gu')->firstOrFail();
        $this->assertSame('પહેલો સુધારો', $override->value);

        // EDIT — the form must open showing the SAVED value, not the
        // app's bundled wording.
        Livewire::test(EditAppStringOverride::class, ['record' => $override->getKey()])
            ->assertFormSet([
                'key' => $key,
                'locale' => 'gu',
                'value' => 'પહેલો સુધારો',
            ])
            // SAVE AGAIN
            ->fillForm(['value' => 'બીજો સુધારો'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('બીજો સુધારો', $override->fresh()->value);

        // …and re-opening shows the second value, i.e. future edits start
        // from what is actually in force.
        Livewire::test(EditAppStringOverride::class, ['record' => $override->getKey()])
            ->assertFormSet(['value' => 'બીજો સુધારો']);

        // REMOVE — from the row action on the list screen.
        Livewire::test(ListAppStringOverrides::class)
            ->assertTableActionVisible('delete', $override)
            ->callTableAction('delete', $override);

        $this->assertDatabaseMissing('temple_app_string_overrides', ['id' => $override->getKey()]);
    }

    public function test_remove_action_is_visible_on_both_the_row_and_the_edit_screen(): void
    {
        $override = AppStringOverride::create([
            'key' => $this->snapshotKey(),
            'locale' => 'hi',
            'value' => 'सुधार',
            'is_active' => true,
        ]);

        Livewire::test(ListAppStringOverrides::class)
            ->assertTableActionVisible('delete', $override)
            ->assertTableBulkActionVisible('delete');

        Livewire::test(EditAppStringOverride::class, ['record' => $override->getKey()])
            ->assertActionVisible('delete');
    }

    public function test_remove_action_is_hidden_without_the_delete_permission(): void
    {
        $override = AppStringOverride::create([
            'key' => $this->snapshotKey(),
            'locale' => 'en',
            'value' => 'Fix',
            'is_active' => true,
        ]);

        $suffix = Str::lower(Str::random(8));
        $role = Role::create(['name' => "l10n_ro_{$suffix}", 'guard_name' => 'admin']);
        $role->syncPermissions(['panel_user', 'view_any_app::string::override', 'view_app::string::override']);
        $viewer = AdminUser::create([
            'name' => 'Viewer',
            'email' => "viewer-{$suffix}@example.test",
            'password' => 'password',
            'is_active' => true,
        ]);
        $viewer->assignRole($role);
        $this->actingAs($viewer->fresh(), 'admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Livewire::test(ListAppStringOverrides::class)
            ->assertTableActionHidden('delete', $override);
    }

    // ---------------------------------------------------------------
    // Snapshot-driven regressions.
    // ---------------------------------------------------------------

    public function test_edit_form_shows_and_resaves_a_key_missing_from_the_snapshot(): void
    {
        // A row pointing at a key the snapshot has never heard of — the
        // exact shape a stale snapshot produces. keyOptions() was built
        // from the snapshot alone, so the Select had no option matching
        // the saved key: Filament resolves the displayed label out of the
        // options array, so the "App string" field rendered BLANK on an
        // existing row. (The hidden state survived, so the row still
        // saved — the damage is that the admin cannot see which string
        // they are editing, and re-picking from the list is impossible.)
        $override = AppStringOverride::create([
            'key' => 'not.in.the.snapshot',
            'locale' => 'gu',
            'value' => 'જૂનું',
            'is_active' => true,
        ]);

        $keySelect = Livewire::test(EditAppStringOverride::class, ['record' => $override->getKey()])
            ->instance()
            ->form
            ->getComponent(
                fn (Component $component): bool => $component instanceof Select
                    && $component->getName() === 'key',
                withHidden: true,
            );

        $this->assertNotNull($keySelect);
        $this->assertSame(
            'not.in.the.snapshot',
            $keySelect->getState(),
        );
        // getOptions() is exactly the list shipped to the browser, and a
        // searchable Select resolves the label of its current value from
        // that list. Miss it and the field paints its placeholder — the
        // admin cannot see which string the row patches.
        $this->assertArrayHasKey(
            'not.in.the.snapshot',
            $keySelect->getOptions(),
            'The saved key is absent from the picker, so the "App string" field renders blank on the Edit screen.',
        );

        Livewire::test(EditAppStringOverride::class, ['record' => $override->getKey()])
            ->assertFormSet(['key' => 'not.in.the.snapshot', 'value' => 'જૂનું'])
            ->fillForm(['value' => 'નવું'])
            ->call('save')
            ->assertHasNoFormErrors();

        $override->refresh();
        $this->assertSame('નવું', $override->value);
        $this->assertSame('not.in.the.snapshot', $override->key, 'The saved key must survive a re-save.');
    }

    public function test_current_text_panel_shows_the_saved_override_not_only_bundled_text(): void
    {
        $key = $this->snapshotKey();
        $snapshot = json_decode((string) file_get_contents(resource_path('app-l10n/gu.json')), true);

        $override = AppStringOverride::create([
            'key' => $key,
            'locale' => 'gu',
            'value' => 'ચાલુ સુધારો',
            'is_active' => true,
        ]);

        $panel = Livewire::test(EditAppStringOverride::class, ['record' => $override->getKey()])
            ->instance()
            ->form
            ->getComponent(
                fn (Component $component): bool => $component instanceof Placeholder
                    && $component->getName() === 'current_text',
                withHidden: true,
            );

        $this->assertNotNull($panel, 'The "what the app shows today" panel is gone from the form.');

        $html = (string) $panel->getContent();

        $this->assertStringContainsString($snapshot[$key], $html, 'Panel must still show the app build text.');
        $this->assertStringContainsString('showing “ચાલુ સુધારો”', $html, 'Panel must show the fix currently in force, not only the bundled text.');
    }

    public function test_duplicate_key_locale_pair_is_a_validation_error_not_a_sql_error(): void
    {
        $key = $this->snapshotKey();

        AppStringOverride::create([
            'key' => $key,
            'locale' => 'gu',
            'value' => 'પહેલો',
            'is_active' => true,
        ]);

        Livewire::test(CreateAppStringOverride::class)
            ->fillForm(['key' => $key, 'locale' => 'gu', 'value' => 'બીજો', 'is_active' => true])
            ->call('create')
            ->assertHasFormErrors(['key']);

        // The same key in a DIFFERENT language is legitimate.
        Livewire::test(CreateAppStringOverride::class)
            ->fillForm(['key' => $key, 'locale' => 'hi', 'value' => 'दूसरा', 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, AppStringOverride::where('key', $key)->count());
    }

    // ---------------------------------------------------------------
    // What the phones actually receive.
    // ---------------------------------------------------------------

    public function test_api_reflects_edits_and_stops_serving_a_removed_fix(): void
    {
        $key = $this->snapshotKey();

        // Cache::forget in the model's booted() is the only thing keeping
        // the 300s API cache honest, so drive this through the real
        // endpoint rather than the model.
        $baseline = $this->getJson('/api/v1/content/app-strings')->assertOk()->json('data');

        $override = AppStringOverride::create([
            'key' => $key,
            'locale' => 'gu',
            'value' => 'પહેલો સુધારો',
            'is_active' => true,
        ]);

        $afterCreate = $this->getJson('/api/v1/content/app-strings')->assertOk()->json('data');
        $this->assertSame('પહેલો સુધારો', $afterCreate['overrides']['gu'][$key] ?? null);
        $this->assertNotSame($baseline['version'], $afterCreate['version']);

        $override->update(['value' => 'બીજો સુધારો']);
        $afterEdit = $this->getJson('/api/v1/content/app-strings')->assertOk()->json('data');
        $this->assertSame('બીજો સુધારો', $afterEdit['overrides']['gu'][$key] ?? null);
        $this->assertNotSame($afterCreate['version'], $afterEdit['version']);

        $override->update(['is_active' => false]);
        $afterDeactivate = $this->getJson('/api/v1/content/app-strings')->assertOk()->json('data');
        $this->assertArrayNotHasKey($key, $afterDeactivate['overrides']['gu']);

        $override->update(['is_active' => true]);
        $override->delete();
        $afterDelete = $this->getJson('/api/v1/content/app-strings')->assertOk()->json('data');
        $this->assertArrayNotHasKey($key, $afterDelete['overrides']['gu']);
        $this->assertSame($baseline['version'], $afterDelete['version'], 'Removing the only fix must restore the original version hash.');
    }

    public function test_cache_is_busted_on_save_and_delete(): void
    {
        $key = $this->snapshotKey();

        $this->getJson('/api/v1/content/app-strings')->assertOk();
        $this->assertTrue(Cache::has(AppStringOverride::CACHE_KEY));

        $override = AppStringOverride::create([
            'key' => $key, 'locale' => 'en', 'value' => 'Fix', 'is_active' => true,
        ]);
        $this->assertFalse(Cache::has(AppStringOverride::CACHE_KEY), 'saved() must forget the payload cache.');

        $this->getJson('/api/v1/content/app-strings')->assertOk();
        $override->delete();
        $this->assertFalse(Cache::has(AppStringOverride::CACHE_KEY), 'deleted() must forget the payload cache.');
    }

    // ---------------------------------------------------------------
    // Drift detector — the root cause, made loud.
    // ---------------------------------------------------------------

    public function test_snapshot_is_in_sync_with_the_app_repo(): void
    {
        // Skipped where the app checkout isn't present (CI runs the portal
        // repo alone); locally and on any machine holding both repos this
        // fails the moment resources/app-l10n/*.json falls behind.
        $source = base_path('../temple_app/assets/l10n');

        if (! is_dir($source)) {
            $this->markTestSkipped('temple_app checkout not present alongside temple-portal.');
        }

        $this->artisan('app:sync-l10n-snapshot', ['--check' => true])
            ->assertExitCode(0);
    }

    public function test_snapshot_carries_all_three_locales_with_identical_key_sets(): void
    {
        $sets = [];
        foreach (['gu', 'hi', 'en'] as $locale) {
            $data = json_decode((string) file_get_contents(resource_path("app-l10n/{$locale}.json")), true);
            $this->assertIsArray($data);
            $this->assertGreaterThan(500, count($data), "app-l10n/{$locale}.json looks truncated.");
            $sets[$locale] = array_keys($data);
            sort($sets[$locale]);
        }

        $this->assertSame($sets['gu'], $sets['hi']);
        $this->assertSame($sets['gu'], $sets['en']);
    }
}
