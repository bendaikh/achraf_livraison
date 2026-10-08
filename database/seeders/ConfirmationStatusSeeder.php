<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Services\Confirmation\ConfirmationStatusProvisioner;
use Illuminate\Database\Seeder;

class ConfirmationStatusSeeder extends Seeder
{
    public function run(): void
    {
        app(ConfirmationStatusProvisioner::class)->provision(Company::default()->id);
    }
}
