<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Support\RoleRecipients;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Who an admin-role reminder reaches (2026-08-15).
 *
 * "Admin role → pujari" used to mean every active pujari with no way to
 * narrow it. A rule can now name individuals; an empty selection keeps the
 * old meaning so rules configured before this are untouched.
 *
 * Both the seva and hall dispatchers ask this one helper, because the hall
 * reminder system is a twin of the seva one and has drifted from it before.
 */
class RoleRecipientsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $name, string $role, bool $active = true): AdminUser
    {
        Role::findOrCreate($role, 'admin');

        $admin = AdminUser::create([
            'name' => $name,
            'email' => str($name)->slug().'@example.test',
            'password' => 'secret-for-tests',
            'is_active' => $active,
        ]);

        $admin->assignRole($role);

        return $admin;
    }

    public function test_an_empty_selection_reaches_everyone_holding_the_role(): void
    {
        $this->admin('Asha', 'pujari');
        $this->admin('Bhavin', 'pujari');
        $this->admin('Chirag', 'accountant');

        $this->assertSame(
            ['Asha', 'Bhavin'],
            RoleRecipients::forRole('pujari')->pluck('name')->all(),
        );

        // null and [] must mean the same thing — the column is nullable and
        // an emptied multi-select saves [].
        $this->assertCount(2, RoleRecipients::forRole('pujari', null));
        $this->assertCount(2, RoleRecipients::forRole('pujari', []));
    }

    public function test_naming_people_narrows_the_rule_to_them(): void
    {
        $asha = $this->admin('Asha', 'pujari');
        $this->admin('Bhavin', 'pujari');

        $this->assertSame(
            ['Asha'],
            RoleRecipients::forRole('pujari', [$asha->id])->pluck('name')->all(),
        );
    }

    public function test_a_named_person_who_lost_the_role_drops_out_on_their_own(): void
    {
        // The point of keeping the role filter even when ids are given: the
        // rule should not need editing when somebody changes job.
        $asha = $this->admin('Asha', 'pujari');
        $bhavin = $this->admin('Bhavin', 'pujari');

        $asha->removeRole('pujari');

        $this->assertSame(
            ['Bhavin'],
            RoleRecipients::forRole('pujari', [$asha->id, $bhavin->id])->pluck('name')->all(),
        );
    }

    public function test_a_deactivated_admin_is_never_reached(): void
    {
        $asha = $this->admin('Asha', 'pujari', active: false);
        $this->admin('Bhavin', 'pujari');

        $this->assertSame(['Bhavin'], RoleRecipients::forRole('pujari')->pluck('name')->all());
        $this->assertCount(0, RoleRecipients::forRole('pujari', [$asha->id]));
    }

    public function test_a_stale_id_matches_nobody_rather_than_falling_back_to_everyone(): void
    {
        // Silently reverting to "all holders" would quietly widen the audience
        // of a rule that was deliberately narrowed.
        $this->admin('Asha', 'pujari');

        $this->assertCount(0, RoleRecipients::forRole('pujari', [999999]));
    }

    public function test_a_blank_role_reaches_nobody(): void
    {
        $this->admin('Asha', 'pujari');

        $this->assertCount(0, RoleRecipients::forRole(''));
        $this->assertCount(0, RoleRecipients::forRole('   '));
    }
}
