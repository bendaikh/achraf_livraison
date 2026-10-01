<?php

namespace App\Support;

use App\Models\User;

/**
 * There is no authentication yet: fall back to user #1 (the seeded admin).
 * Everything that is "per user" goes through here so it becomes truly per-user
 * as soon as auth is plugged in.
 */
class CurrentUser
{
    public static function get(): ?User
    {
        return auth()->user() ?? User::query()->find(1) ?? User::query()->orderBy('id')->first();
    }

    public static function id(): ?int
    {
        return self::get()?->id;
    }
}
