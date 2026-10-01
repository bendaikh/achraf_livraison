<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserPreference;
use App\Support\CurrentUser;
use Illuminate\Http\Request;

class PreferenceController extends Controller
{
    public function show(string $key)
    {
        $userId = CurrentUser::id();
        $pref = $userId ? UserPreference::query()->where('user_id', $userId)->where('key', $key)->first() : null;

        return response()->json(['key' => $key, 'user_id' => $userId, 'value' => $pref?->value]);
    }

    public function update(Request $request, string $key)
    {
        abort_unless(preg_match('/^[a-z0-9_.-]{1,100}$/i', $key), 422, 'Clé de préférence invalide.');
        $data = $request->validate(['value' => ['present', 'array']]);
        $userId = CurrentUser::id();
        abort_unless($userId, 422, 'Aucun utilisateur courant.');

        $pref = UserPreference::query()->updateOrCreate(
            ['user_id' => $userId, 'key' => $key],
            ['value' => $data['value']],
        );

        return response()->json(['key' => $key, 'user_id' => $userId, 'value' => $pref->value]);
    }
}
