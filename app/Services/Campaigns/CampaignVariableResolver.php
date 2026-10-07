<?php

namespace App\Services\Campaigns;

use App\Models\ClientVehicle;
use App\Models\Company;
use App\Models\Order;
use App\Services\Clients\ClientService;

/**
 * Maps template variable slots to client / vehicle / order / fixed values.
 *
 * variable_mapping example:
 * [
 *   {"slot": 1, "source": "client_name"},
 *   {"slot": 2, "source": "vehicle_brand"},
 *   {"slot": 3, "source": "fixed", "value": "PROMO20"},
 *   {"slot": 4, "source": "product_name"},
 * ]
 */
class CampaignVariableResolver
{
    public function __construct(protected ClientService $clients) {}

    /**
     * @return array{variables: list<string>, context: array<string, string>, preview: string}
     */
    public function resolve(
        Company $company,
        string $phoneKey,
        ?string $templateBody,
        array $mapping,
        ?object $clientRow = null
    ): array {
        $clientRow ??= $this->clients->find($phoneKey);
        $vehicle = ClientVehicle::query()
            ->where('company_id', $company->id)
            ->where('phone_key', $phoneKey)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();

        $lastOrder = Order::query()->where('phone_key', $phoneKey)->latest('id')->first();

        $context = [
            'client_name' => (string) ($clientRow->customer_name ?? ''),
            'client_phone' => (string) ($clientRow->phone ?? $phoneKey),
            'client_email' => (string) ($clientRow->email ?? ''),
            'client_city' => $this->extractCity($clientRow->shipping_address ?? null),
            'company_name' => (string) $company->name,
            'vehicle_brand' => (string) ($vehicle?->brand ?? ''),
            'vehicle_model' => (string) ($vehicle?->model ?? ''),
            'vehicle_year' => $vehicle?->year ? (string) $vehicle->year : '',
            'vehicle_generation' => (string) ($vehicle?->generation ?? ''),
            'product_name' => (string) ($lastOrder?->productName() ?? ''),
            'order_number' => (string) ($lastOrder?->reference() ?? ''),
            'promo_code' => '',
            'orders_count' => (string) ($clientRow->orders ?? ''),
            'total_spent' => (string) ($clientRow->total ?? ''),
        ];

        $variables = [];
        if ($mapping === []) {
            // Fallback: sequential {{1}} style from common fields
            $variables = array_values(array_filter([
                $context['client_name'],
            ], fn ($v) => $v !== ''));
        } else {
            usort($mapping, fn ($a, $b) => ((int) ($a['slot'] ?? 0)) <=> ((int) ($b['slot'] ?? 0)));
            foreach ($mapping as $map) {
                $source = (string) ($map['source'] ?? 'fixed');
                if ($source === 'fixed') {
                    $variables[] = (string) ($map['value'] ?? '');
                } elseif ($source === 'promo_code') {
                    $variables[] = (string) ($map['value'] ?? $context['promo_code']);
                } else {
                    $variables[] = (string) ($context[$source] ?? $map['value'] ?? '');
                }
            }
        }

        $preview = $this->renderPreview($templateBody ?? '', $variables, $context);

        return [
            'variables' => $variables,
            'context' => $context,
            'preview' => $preview,
        ];
    }

    public function renderPreview(?string $body, array $positional, array $named = []): string
    {
        $text = (string) $body;
        foreach ($named as $key => $value) {
            $text = str_replace('{{'.$key.'}}', (string) $value, $text);
        }
        foreach ($positional as $i => $value) {
            $n = $i + 1;
            $text = str_replace(['{{'.$n.'}}', '{{'.$n.'.}}'], (string) $value, $text);
        }

        return $text;
    }

    protected function extractCity(mixed $shipping): string
    {
        if (is_string($shipping)) {
            $decoded = json_decode($shipping, true);
            if (is_array($decoded)) {
                return (string) ($decoded['city'] ?? '');
            }

            return '';
        }
        if (is_array($shipping)) {
            return (string) ($shipping['city'] ?? '');
        }

        return '';
    }
}
