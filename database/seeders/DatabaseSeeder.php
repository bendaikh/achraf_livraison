<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(ConfirmationStatusSeeder::class);
        $this->call(WhatsAppQuickReplySeeder::class);

        User::query()->updateOrCreate(
            ['email' => 'superadmin@lavfast-flow.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('SuperAdmin@2026'),
                'role' => 'superadmin',
                'email_verified_at' => now(),
            ]
        );
    }
}
