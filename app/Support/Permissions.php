<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;

/**
 * Role → ability resolution (config/permissions.php + per-company override in the
 * "role_permissions" setting). Registered as a Gate::before hook, so `$user->can('orders.ship')`
 * and `->middleware('can:orders.ship')` work everywhere.
 */
class Permissions
{
    public static function abilities(): array
    {
        return (array) config('permissions.abilities', []);
    }

    public static function isKnown(string $ability): bool
    {
        return array_key_exists($ability, self::abilities());
    }

    /** @return list<string> roles granted for an ability */
    public static function rolesFor(string $ability): array
    {
        $overrides = (array) Setting::getValue('role_permissions', []);
        if (isset($overrides[$ability]) && is_array($overrides[$ability])) {
            return array_values($overrides[$ability]);
        }

        return (array) (self::abilities()[$ability]['roles'] ?? []);
    }

    public static function allows(?User $user, string $ability): bool
    {
        if (! $user || $user->isLivreur()) {
            return false;
        }
        if ($user->isSuperAdmin()) {
            return true;
        }

        return in_array($user->role, self::rolesFor($ability), true);
    }

    /** @return list<string> */
    public static function forUser(?User $user): array
    {
        return array_values(array_filter(array_keys(self::abilities()), fn ($a) => self::allows($user, $a)));
    }
}
