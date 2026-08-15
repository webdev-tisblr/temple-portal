<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AdminUser;
use Illuminate\Support\Collection;

/**
 * Who an "admin role" reminder rule actually reaches.
 *
 * Shared by the seva and hall dispatchers, which ask the same question and
 * must answer it identically — the hall reminder system was written as a
 * twin of the seva one and has already drifted from it once.
 */
final class RoleRecipients
{
    /**
     * Active holders of $role, narrowed to $userIds when the rule names
     * specific people.
     *
     * An empty selection means EVERYONE holding the role — the meaning
     * admin_role carried before naming individuals was possible, so rules
     * configured before that are unaffected.
     *
     * The role and is_active filters apply in both cases deliberately: a
     * named person who later loses the role or leaves must drop out on
     * their own, rather than keeping the reminder until someone remembers
     * to edit the rule. A stale id simply matches nobody.
     *
     * @param  array<int, mixed>|null  $userIds
     * @return Collection<int, AdminUser>
     */
    public static function forRole(string $role, ?array $userIds = null): Collection
    {
        $role = trim($role);

        if ($role === '') {
            return new Collection;
        }

        $chosen = array_values(array_filter((array) $userIds));

        return AdminUser::query()
            ->role($role)
            ->where('is_active', true)
            ->when($chosen !== [], fn ($query) => $query->whereIn('id', $chosen))
            ->orderBy('name')
            ->get();
    }
}
