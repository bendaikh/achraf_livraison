<?php

namespace Database\Seeders;

use App\Models\ClientAudienceSegment;
use App\Models\ClientTag;
use App\Models\ClientTagAssignment;
use App\Models\ClientVehicle;
use App\Models\Company;
use App\Models\Order;
use App\Services\Clients\ClientService;
use Illuminate\Database\Seeder;

/**
 * Optional demo data for WhatsApp Campaigns (tags, vehicles, segment).
 * Not called from DatabaseSeeder — run explicitly:
 *   php artisan db:seed --class=WhatsAppCampaignDemoSeeder
 */
class WhatsAppCampaignDemoSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::default();
        $company->forceFill([
            'timezone' => 'Africa/Casablanca',
            'features' => array_merge($company->features ?? [], ['client_vehicles' => true]),
        ])->save();

        $accessoires = ClientTag::query()->firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Client accessoires'],
            ['color' => '#0d9488']
        );
        ClientTag::query()->firstOrCreate(
            ['company_id' => $company->id, 'name' => 'VIP'],
            ['color' => '#ca8a04']
        );

        // Attach tag + vehicle to a few existing clients if any
        $keys = Order::query()->whereNotNull('phone_key')->distinct()->limit(5)->pluck('phone_key');
        foreach ($keys as $i => $key) {
            ClientTagAssignment::query()->firstOrCreate(
                ['client_tag_id' => $accessoires->id, 'phone_key' => $key],
                ['company_id' => $company->id]
            );
            ClientVehicle::query()->firstOrCreate(
                [
                    'company_id' => $company->id,
                    'phone_key' => $key,
                    'brand' => 'Peugeot',
                    'model' => '208',
                ],
                [
                    'year' => 2020 + ($i % 5),
                    'is_primary' => true,
                ]
            );
        }

        ClientAudienceSegment::query()->firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Peugeot 208 2020+ accessoires'],
            [
                'description' => 'Tag Client accessoires + Peugeot 208 2020–2025',
                'definition' => [
                    'logic' => 'and',
                    'rules' => [
                        ['type' => 'tag', 'operator' => 'has_any', 'tag_ids' => [$accessoires->id]],
                        ['type' => 'vehicle', 'brand' => 'Peugeot', 'model' => '208', 'year_min' => 2020, 'year_max' => 2025],
                    ],
                ],
            ]
        );

        $this->command?->info('Demo campagnes: tags, véhicules, segment pour company #'.$company->id.' ('.ClientService::class.' phone keys: '.$keys->count().')');
    }
}
