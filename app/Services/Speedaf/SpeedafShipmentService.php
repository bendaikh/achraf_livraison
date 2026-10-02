<?php

namespace App\Services\Speedaf;

use App\Models\DeliveryStatus;
use App\Models\Order;
use App\Models\SpeedafSetting;
use App\Models\SpeedafShipment;
use App\Models\User;
use App\Services\OrderWorkflow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Commandes ⇄ Speedaf: builds the createOrder payload from an Order, stores the waybill,
 * cancels it, prints labels and applies the tracking events (polling or webhook) onto the
 * configurable delivery statuses.
 */
class SpeedafShipmentService
{
    protected SpeedafClient $client;

    public function __construct(protected SpeedafSetting $settings, protected OrderWorkflow $workflow, ?SpeedafClient $client = null)
    {
        $this->client = $client ?? SpeedafClient::for($settings);
    }

    public static function for(SpeedafSetting $settings): self
    {
        return new self($settings, app(OrderWorkflow::class));
    }

    public function client(): SpeedafClient
    {
        return $this->client;
    }

    /* ------------------------------------------------------------------ create */

    /** @throws SpeedafException */
    public function assertReady(): void
    {
        $missing = $this->settings->missingForShipping();
        if ($missing) {
            throw new SpeedafException('Intégration Speedaf incomplète : '.implode(', ', $missing).' (Intégrations → Speedaf).');
        }
    }

    /** Active (not cancelled) shipment of the order, if any. */
    public static function activeShipment(Order $order): ?SpeedafShipment
    {
        return SpeedafShipment::query()->where('order_id', $order->id)
            ->where('state', '!=', SpeedafShipment::STATE_CANCELLED)
            ->latest('id')->first();
    }

    /** @throws SpeedafException */
    public function createShipment(Order $order, ?User $user = null): SpeedafShipment
    {
        $this->assertReady();

        if ($existing = self::activeShipment($order)) {
            throw new SpeedafException("La commande {$order->reference()} est déjà envoyée à Speedaf (n° {$existing->bill_code}).");
        }
        if (! $order->isConfirmed()) {
            throw new SpeedafException("La commande {$order->reference()} doit être confirmée avant l’envoi à Speedaf.");
        }
        if (in_array($order->deliveryCategory(), ['succes', 'annulation', 'retour'], true)) {
            throw new SpeedafException("La commande {$order->reference()} est au statut « {$order->deliveryStatusLabel()} » : envoi impossible.");
        }

        $attempt = SpeedafShipment::query()->where('order_id', $order->id)->count() + 1;
        $payload = $this->buildPayload($order, $attempt);

        $data = $this->client->createOrder($payload);

        $shipment = SpeedafShipment::create([
            'company_id' => $this->settings->company_id,
            'order_id' => $order->id,
            'environment' => $this->settings->environment,
            'bill_code' => (string) $data['billCode'],
            'custom_order_no' => $payload['customOrderNo'],
            'state' => SpeedafShipment::STATE_CREATED,
            'label_url' => $data['labelUrl'] ?? null,
            'request_payload' => $payload,
            'create_response' => $data,
            'last_action_name' => 'Commande créée chez Speedaf',
            'created_by' => $user?->id,
        ]);

        $order->carrier = 'Speedaf';
        $order->appendHistory('speedaf_created', "Envoyée à Speedaf (n° {$shipment->bill_code})", $user, ['bill_code' => $shipment->bill_code]);
        $order->save();

        return $shipment;
    }

    /**
     * Bulk send (Commandes multi-select). Never throws: returns one result per order.
     *
     * @return array<int, array{order_id:int, reference:string, success:bool, bill_code:?string, message:string}>
     */
    public function createMany(iterable $orders, ?User $user = null): array
    {
        $results = [];
        foreach ($orders as $order) {
            try {
                $shipment = $this->createShipment($order, $user);
                $results[] = ['order_id' => $order->id, 'reference' => $order->reference(), 'success' => true, 'bill_code' => $shipment->bill_code, 'message' => "Envoyée (n° {$shipment->bill_code})"];
            } catch (SpeedafException $e) {
                $results[] = ['order_id' => $order->id, 'reference' => $order->reference(), 'success' => false, 'bill_code' => null, 'message' => $e->getMessage()];
                // Configuration problems apply to every order: stop early.
                if ($e->errorCode && str_starts_with($e->errorCode, '70')) {
                    break;
                }
            } catch (Throwable $e) {
                Log::error('Speedaf create failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
                $results[] = ['order_id' => $order->id, 'reference' => $order->reference(), 'success' => false, 'bill_code' => null, 'message' => 'Erreur inattendue lors de l’envoi à Speedaf.'];
            }
        }

        return $results;
    }

    /** Speedaf createOrder payload (PDF §3.1). @throws SpeedafException */
    public function buildPayload(Order $order, int $attempt = 1): array
    {
        $s = $this->settings;
        $address = is_array($order->shipping_address) ? $order->shipping_address : [];

        $name = trim((string) ($order->customer_name ?: trim(($address['first_name'] ?? '').' '.($address['last_name'] ?? '')) ?: ($address['name'] ?? '')));
        $phone = self::normalizePhone($order->phone ?: ($address['phone'] ?? null));
        $city = trim((string) $order->shippingCity());
        $street = trim((string) $order->shippingAddressLine());

        $missing = [];
        if ($name === '') {
            $missing[] = 'nom du client';
        }
        if ($phone === '') {
            $missing[] = 'téléphone';
        }
        if ($city === '') {
            $missing[] = 'ville';
        }
        if ($missing) {
            throw new SpeedafException("Commande {$order->reference()} : ".implode(', ', $missing).' manquant(s) pour Speedaf.');
        }

        $country = strtoupper($s->country_code ?: 'MA');
        $currency = strtoupper($order->currency ?: ($s->currency ?: 'MAD'));
        $resolver = new SpeedafAreaResolver($this->client);
        $accept = $resolver->resolve($city);
        $sender = $resolver->resolve($s->sender_city, $s->sender_district);

        $items = $this->items($order, $currency);
        $qty = array_sum(array_column($items, 'goodsQTY')) ?: 1;
        $weight = $this->weight($order, $items);
        $cod = $order->isCod() ? round((float) $order->total_price, 2) : 0.0;

        $customOrderNo = self::customOrderNo($order, $attempt);

        $payload = [
            'customOrderNo' => $customOrderNo,
            'billCode' => '',
            'customerCode' => (string) $s->customer_code,
            'platformSource' => (string) $s->platform_source,
            'parcelType' => $s->parcel_type ?: 'PT01',
            'deliveryType' => $s->delivery_type ?: 'DE01',
            'transportType' => $s->transport_type ?: 'TT01',
            'shipType' => $s->ship_type ?: 'ST01',
            'payMethod' => $s->pay_method ?: 'PA02',
            'isAllowOpen' => $s->allow_open ? 1 : 0,
            'acceptName' => Str::limit($name, 100, ''),
            'acceptPostCode' => (string) ($address['zip'] ?? ''),
            'acceptMobile' => $phone,
            'acceptEmail' => filter_var($order->email, FILTER_VALIDATE_EMAIL) ? Str::limit($order->email, 50, '') : '',
            'acceptAddress' => Str::limit($street !== '' ? $street.', '.$city : $city, 500, ''),
            'acceptCountryCode' => $country,
            'acceptCountryName' => self::countryName($country),
            'acceptProvinceName' => Str::limit($accept['province'], 50, ''),
            'acceptCityName' => Str::limit($accept['city'], 50, ''),
            'acceptDistrictName' => Str::limit($accept['district'], 50, ''),
            'sendName' => Str::limit((string) $s->sender_name, 100, ''),
            'sendAddress' => Str::limit((string) $s->sender_address, 500, ''),
            'sendMobile' => self::normalizePhone($s->sender_mobile),
            'sendCountryCode' => $country,
            'sendCountryName' => self::countryName($country),
            'sendProvinceName' => Str::limit(($s->sender_province ?: $sender['province']) ?: (string) $s->sender_city, 50, ''),
            'sendCityName' => Str::limit($sender['city'] ?: (string) $s->sender_city, 50, ''),
            'sendDistrictName' => Str::limit(($s->sender_district ?: $sender['district']) ?: (string) $s->sender_city, 50, ''),
            'parcelWeight' => $weight,
            'piece' => 1,
            'goodsQTY' => $qty,
            'codFee' => $cod,
            'currencyType' => $currency,
            'pickUpAging' => (int) $s->pickup_aging,
            'remark' => Str::limit(trim((string) $order->note), 200, ''),
            'itemList' => $items,
        ];

        return $payload;
    }

    /**
     * Speedaf deduplicates on customOrderNo (same number => same waybill returned), so it must be
     * unique per installation and per attempt: "LF" + 4-char install hash + order id (+ attempt).
     */
    public static function customOrderNo(Order $order, int $attempt = 1): string
    {
        $install = strtoupper(substr(hash('sha256', (string) config('app.key').'|speedaf'), 0, 4));

        return Str::limit('LF'.$install.'-'.$order->id.($attempt > 1 ? '-'.$attempt : ''), 50, '');
    }

    protected function items(Order $order, string $currency): array
    {
        $lines = is_array($order->line_items) ? array_values($order->line_items) : [];
        $totalQty = max(1, array_sum(array_map(fn ($l) => max(1, (int) ($l['quantity'] ?? 1)), $lines)));
        $fallbackUnit = round(((float) $order->total_price) / $totalQty, 2);
        $goodsType = $this->settings->goods_type ?: 'IT01';

        $items = [];
        foreach ($lines as $i => $line) {
            $qty = max(1, (int) ($line['quantity'] ?? 1));
            $price = isset($line['price']) && is_numeric($line['price']) ? (float) $line['price'] : $fallbackUnit;
            $grams = isset($line['grams']) && is_numeric($line['grams']) ? (float) $line['grams'] : 0;
            $items[] = [
                'sku' => Str::limit((string) ($line['sku'] ?? '') ?: 'ITEM-'.($i + 1), 50, ''),
                'goodsName' => Str::limit((string) ($line['title'] ?? $line['name'] ?? 'Article'), 100, ''),
                'goodsQTY' => $qty,
                'goodsValue' => round($price, 2),
                'currencyType' => $currency,
                'goodsType' => $goodsType,
                'goodsWeight' => $grams > 0 ? round($grams / 1000, 3) : null,
                'goodsUrl' => '',
                'blInsure' => 0,
                'battery' => 0,
            ];
        }
        if ($items === []) {
            $items[] = [
                'sku' => 'ITEM-1', 'goodsName' => Str::limit($order->productName() ?: 'Article', 100, ''), 'goodsQTY' => 1,
                'goodsValue' => round((float) $order->total_price, 2), 'currencyType' => $currency, 'goodsType' => $goodsType,
                'goodsWeight' => null, 'goodsUrl' => '', 'blInsure' => 0, 'battery' => 0,
            ];
        }

        return array_map(fn ($item) => array_filter($item, fn ($v) => $v !== null), $items);
    }

    protected function weight(Order $order, array $items): float
    {
        $sum = 0.0;
        foreach ($items as $item) {
            $sum += ((float) ($item['goodsWeight'] ?? 0)) * (int) $item['goodsQTY'];
        }
        $weight = $sum > 0 ? $sum : (float) ($this->settings->default_weight ?: 1);

        return round(max($weight, 0.001), 3);
    }

    /** "+212 6 12-34-56-78" / "00212612345678" / "212612345678" → "0612345678". */
    public static function normalizePhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if ($digits === '') {
            return '';
        }
        if (str_starts_with($digits, '00212')) {
            $digits = '0'.substr($digits, 5);
        } elseif (str_starts_with($digits, '212') && strlen($digits) === 12) {
            $digits = '0'.substr($digits, 3);
        } elseif (strlen($digits) === 9 && in_array($digits[0], ['5', '6', '7'], true)) {
            $digits = '0'.$digits;
        }

        return Str::limit($digits, 20, '');
    }

    public static function countryName(string $code): string
    {
        return [
            'MA' => 'Morocco', 'NG' => 'Nigeria', 'GH' => 'Ghana', 'EG' => 'Egypt', 'KE' => 'Kenya', 'UG' => 'Uganda',
            'CN' => 'China', 'BD' => 'Bangladesh', 'PK' => 'Pakistan', 'SA' => 'Saudi Arabia', 'AE' => 'Arab Emirates', 'TR' => 'Turkey',
        ][$code] ?? $code;
    }

    /* ------------------------------------------------------------------ cancel */

    /** @throws SpeedafException */
    public function cancel(Order $order, string $reason = 'Annulation expéditeur', ?User $user = null): SpeedafShipment
    {
        $shipment = self::activeShipment($order);
        if (! $shipment) {
            throw new SpeedafException("La commande {$order->reference()} n’a pas d’envoi Speedaf actif.");
        }
        if ($shipment->state !== SpeedafShipment::STATE_CREATED) {
            throw new SpeedafException('Cet envoi Speedaf est déjà finalisé et ne peut plus être annulé.');
        }

        $results = $this->client->cancelOrders([$shipment->bill_code], $reason, $user?->name, null);
        $row = collect($results)->firstWhere('billCode', $shipment->bill_code) ?? ($results[0] ?? null);
        $shipment->last_response = ['cancel' => $results];

        if (! is_array($row) || ($row['success'] ?? false) !== true) {
            $message = is_array($row) ? ($row['message'] ?? null) : null;
            $shipment->last_error = 'Annulation refusée'.($message ? ' : '.$message : '');
            $shipment->save();

            throw new SpeedafException('Speedaf a refusé l’annulation'.($message ? ' : '.$message : ' (le colis est peut-être déjà ramassé).'));
        }

        $shipment->forceFill([
            'state' => SpeedafShipment::STATE_CANCELLED,
            'cancelled_at' => now(),
            'last_error' => null,
            'last_action_name' => 'Annulée chez Speedaf',
        ])->save();

        $order->appendHistory('speedaf_cancelled', "Envoi Speedaf annulé (n° {$shipment->bill_code})", $user, ['reason' => $reason]);
        if ($order->carrier === 'Speedaf') {
            $order->carrier = null;
        }
        $order->save();

        return $shipment;
    }

    /* ------------------------------------------------------------------ labels */

    /**
     * @param  SpeedafShipment[]  $shipments
     * @return array<string, array{url: ?string, base64: ?string}> keyed by bill code
     *
     * @throws SpeedafException
     */
    public function labels(array $shipments): array
    {
        $codes = array_values(array_filter(array_map(fn ($s) => $s->bill_code, $shipments)));
        if ($codes === []) {
            throw new SpeedafException('Aucun numéro de suivi Speedaf à imprimer.');
        }

        $data = $this->client->printLabels($codes);
        $out = [];
        foreach ((array) ($data['orderLabels'] ?? []) as $label) {
            $code = (string) ($label['waybillNo'] ?? '');
            if ($code !== '') {
                $out[$code] = ['url' => $label['labelUrl'] ?? null, 'base64' => ($label['labelBase64'] ?? '') ?: null];
            }
        }
        // Some responses only carry "urls".
        foreach ((array) ($data['urls'] ?? []) as $url) {
            foreach ($codes as $code) {
                if (! isset($out[$code]) && str_contains((string) $url, $code)) {
                    $out[$code] = ['url' => $url, 'base64' => null];
                }
            }
        }
        if (count($codes) === 1 && ! isset($out[$codes[0]]) && ! empty($data['urls'][0])) {
            $out[$codes[0]] = ['url' => $data['urls'][0], 'base64' => null];
        }

        foreach ($shipments as $shipment) {
            if (! empty($out[$shipment->bill_code]['url'])) {
                $shipment->forceFill(['label_url' => $out[$shipment->bill_code]['url']])->save();
            }
        }
        if ($out === []) {
            throw new SpeedafException('Speedaf n’a retourné aucune étiquette.');
        }

        return $out;
    }

    /* ------------------------------------------------------------------ tracking */

    /**
     * Polls §5.1 for the given shipments (chunks of 50) and applies new events.
     *
     * @param  iterable<SpeedafShipment>  $shipments
     * @return array{checked:int, updated:int, errors:array<int,string>}
     */
    public function sync(iterable $shipments): array
    {
        $byCode = [];
        foreach ($shipments as $shipment) {
            if ($shipment->bill_code) {
                $byCode[$shipment->bill_code] = $shipment;
            }
        }
        $stats = ['checked' => 0, 'updated' => 0, 'errors' => []];

        foreach (array_chunk(array_keys($byCode), 50) as $chunk) {
            try {
                $rows = $this->client->track($chunk);
            } catch (SpeedafException $e) {
                $stats['errors'][] = $e->getMessage();

                continue;
            }
            foreach ($rows as $row) {
                $code = (string) ($row['mailNo'] ?? '');
                if (! isset($byCode[$code])) {
                    continue;
                }
                $stats['checked']++;
                if ($this->applyTracks($byCode[$code], (array) ($row['tracks'] ?? []))) {
                    $stats['updated']++;
                }
            }
        }

        $this->settings->forceFill(['last_synced_at' => now()])->save();

        return $stats;
    }

    /**
     * Stores the tracking records and applies the latest one to the order (when newer than
     * the last applied event and mapped to a delivery status). Returns true when the order
     * delivery status changed.
     */
    public function applyTracks(SpeedafShipment $shipment, array $tracks, string $source = 'sync'): bool
    {
        $shipment->last_synced_at = now();
        if ($tracks === []) {
            $shipment->save();

            return false;
        }

        // Merge with the known tracks (webhooks only push the new ones), dedupe, sort by time.
        $all = array_merge((array) ($shipment->tracks ?? []), $tracks);
        $unique = [];
        foreach ($all as $t) {
            if (! is_array($t)) {
                continue;
            }
            $key = ($t['time'] ?? '').'|'.($t['action'] ?? '').'|'.($t['subAction'] ?? '');
            $unique[$key] = $t;
        }
        $all = array_values($unique);
        usort($all, fn ($a, $b) => strcmp((string) ($a['time'] ?? ''), (string) ($b['time'] ?? '')));
        $shipment->tracks = $all;

        $latest = end($all);
        $eventAt = self::eventTime($latest);
        $sameEvent = $shipment->last_event_at && $eventAt && $eventAt->equalTo($shipment->last_event_at)
            && $shipment->last_action === (isset($latest['action']) ? (string) $latest['action'] : null)
            && $shipment->last_sub_action === (isset($latest['subAction']) ? (string) $latest['subAction'] : null);
        $older = $shipment->last_event_at && $eventAt && $eventAt->lt($shipment->last_event_at);
        $isNew = ! $sameEvent && ! $older;

        if (! $isNew) {
            $shipment->save();

            return false;
        }

        $key = SpeedafStatusMap::keyFor($latest['action'] ?? null, $latest['subAction'] ?? null);
        $message = $latest['msgLoc'] ?? $latest['msgEng'] ?? $latest['message'] ?? null;

        $shipment->forceFill([
            'last_action' => isset($latest['action']) ? (string) $latest['action'] : null,
            'last_sub_action' => isset($latest['subAction']) ? (string) $latest['subAction'] : null,
            'last_action_name' => Str::limit((string) (SpeedafStatusMap::label($key) ?? ($latest['actionName'] ?? $key)), 120, ''),
            'last_message' => $message ? Str::limit((string) $message, 500, '') : null,
            'last_event_at' => $eventAt ?? now(),
            'last_error' => null,
        ]);
        if (in_array($key, SpeedafStatusMap::DELIVERED_CODES, true)) {
            $shipment->state = SpeedafShipment::STATE_DELIVERED;
        } elseif (in_array($key, SpeedafStatusMap::RETURNED_CODES, true)) {
            $shipment->state = SpeedafShipment::STATE_RETURNED;
        }
        $shipment->save();

        return $this->applyToOrder($shipment, $key, $message, $source);
    }

    protected function applyToOrder(SpeedafShipment $shipment, ?string $key, ?string $message, string $source): bool
    {
        if (! $key) {
            return false;
        }
        $statusCode = $this->settings->mapping()[$key] ?? null;
        if (! $statusCode) {
            return false;
        }
        $status = DeliveryStatus::findByCode($statusCode);
        $order = $shipment->order;
        if (! $status || ! $status->is_active || ! $order || $order->delivery_status === $status->code) {
            return false;
        }

        $data = [];
        if (in_array($status->category, ['echec', 'injoignable', 'annulation', 'retour'], true) && $message) {
            $data['reason'] = Str::limit($message, 250, '');
        }
        if ($status->category === 'succes') {
            $cod = (float) ($shipment->request_payload['codFee'] ?? ($order->isCod() ? $order->total_price : 0));
            $data['collected_amount'] = $cod;
        }

        $label = SpeedafStatusMap::label($key) ?? $key;
        $this->workflow->applyCarrierStatus($order, $status, $data, "Speedaf ({$source}) : {$label}".($message ? ' — '.Str::limit($message, 150) : ''));

        return true;
    }

    public static function eventTime(?array $track): ?Carbon
    {
        $time = $track['time'] ?? null;
        if (! $time) {
            return null;
        }
        try {
            $tz = isset($track['timezone']) && is_numeric($track['timezone'])
                ? sprintf('%+03d:00', (int) $track['timezone'])
                : config('app.timezone');

            return Carbon::parse($time, $tz)->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }
}
