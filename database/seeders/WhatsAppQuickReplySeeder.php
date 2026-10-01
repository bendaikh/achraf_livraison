<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\WhatsAppQuickReply;
use Illuminate\Database\Seeder;

class WhatsAppQuickReplySeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::default();

        $defaults = [
            [
                'title' => 'Commande disponible',
                'body' => 'Bonjour, votre commande est bien disponible.',
                'category' => 'Confirmation',
                'sort_order' => 1,
            ],
            [
                'title' => 'Confirmer adresse',
                'body' => 'Pouvez-vous nous confirmer votre adresse ?',
                'category' => 'Confirmation',
                'sort_order' => 2,
            ],
            [
                'title' => 'Merci confirmation',
                'body' => 'Merci pour votre confirmation.',
                'category' => 'Confirmation',
                'sort_order' => 3,
            ],
        ];

        foreach ($defaults as $row) {
            WhatsAppQuickReply::query()->firstOrCreate(
                [
                    'company_id' => $company->id,
                    'title' => $row['title'],
                ],
                [
                    'body' => $row['body'],
                    'category' => $row['category'],
                    'sort_order' => $row['sort_order'],
                    'is_active' => true,
                ]
            );
        }
    }
}
