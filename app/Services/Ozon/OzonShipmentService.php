<?php

namespace App\Services\Ozon;

use App\Models\DeliveryStatus;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\OzonApiLog;
use App\Models\OzonDeliveryNote;
use App\Models\OzonDeliveryNoteItem;
use App\Models\OzonSetting;
use App\Models\OzonShipment;
use App\Models\SavRequest;
use App\Models\Setting;
use App\Models\User;
use App\Services\OrderWorkflow;
use App\Services\Speedaf\SpeedafShipmentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Commandes ⇄ Ozon Express. Public entry points (also meant for the future Automatisations
 * engine, which must call them explicitly — nothing here sends parcels on its own):
 *   createParcel(), refreshTracking(), syncStatus(), addToDeliveryNote() / createDeliveryNote().
 * Every API failure is written to ozon_api_logs (redacted) so it can be retried from the UI.
 */
class OzonShipmentService
{
    public const COLOR = '#0d9488';

    public const LABEL = 'Ozon Express';

    protected OzonClient $client;

    public function __construct(
        protected OzonSetting $settings,
        protected OrderWorkflow $workflow,
        protected OzonCityService $cities,
        ?OzonClient $client = null,
    ) {
        $this->client = $client ?? OzonClient::for($settings);
    }

    public static function for(OzonSetting $settings): self
    {
        return new self($settings, app(OrderWorkflow::class), app(OzonCityService::class));
    }

    public static function forCompany(int $companyId): self
    {
        return self::for(OzonSetting::forCompany($companyId));
    }

    public function settings(): OzonSetting
    {
        return $this->settings;
    }

    public function client(): OzonClient
    {
        return $this->client;
    }

    /** Bulk actions (more than one order at once) stay off until the full cycle has been validated. */
    public static function bulkEnabled(): bool
    {
        return (bool) Setting::getValue('ozon_bulk_enabled', false);
    }

    /** @throws OzonException */
    public function assertReady(): void
    {
        if ($missing = $this->settings->missingForShipping()) {
            throw new OzonException('Intégration Ozon Express incomplète : '.implode(', ', $missing).' (Intégrations → Transporteurs → Ozon Express).', '', null, null, true);
        }
    }

    public static function activeShipment(Order $order, ?int $savRequestId = null): ?OzonShipment
    {
        $q = OzonShipment::query()->where('order_id', $order->id)->active();
        $savRequestId ? $q->where('sav_request_id', $savRequestId) : $q->whereNull('sav_request_id');

        return $q->latest('id')->first();
    }

    /* ------------------------------------------------------------------ build / preview */

    /**
     * What will be sent (validation popup): client, city (mapped Ozon ID), COD, options and the
     * list of blocking errors. Never calls the API.
     */
    public function preview(Order $order, array $options = [], ?SavRequest $sav = null): array
    {
        $existing = self::activeShipment($order, $sav?->id);
        $errors = [];
        $built = null;
        try {
            $built = $this->build($order, $options, $sav);
        } catch (OzonException $e) {
            $errors = array_values(array_filter(explode("\n", $e->getMessage())));
        }
        $data = $built['summary'] ?? $this->summary($order, $options, $sav);

        return $data + [
            'order_id' => $order->id,
            'reference' => $order->reference(),
            'sav_request_id' => $sav?->id,
            'errors' => $errors,
            'already' => $existing ? $existing->toSummary() : null,
            'can_send' => ! $existing && $errors === [],
        ];
    }

    /** Raw values of the order (also when it cannot be sent). */
    protected function summary(Order $order, array $options, ?SavRequest $sav): array
    {
        $address = is_array($order->shipping_address) ? $order->shipping_address : [];
        $city = trim((string) ($sav?->city ?: $order->shippingCity()));
        $ozonCity = $city !== '' ? $this->cities->resolve((int) $this->settings->company_id, $city) : null;
        $kind = $sav && $sav->type === 'echange' ? 'echange' : 'livraison';

        return [
            'kind' => $kind,
            'receiver' => trim((string) ($sav?->customer_name ?: ($order->customer_name ?: trim(($address['first_name'] ?? '').' '.($address['last_name'] ?? '')) ?: ($address['name'] ?? '')))),
            'phone' => SpeedafShipmentService::normalizePhone($sav?->phone ?: ($order->phone ?: ($address['phone'] ?? null))),
            'city' => $city,
            'ozon_city' => $ozonCity?->toOption(),
            'city_suggestions' => $ozonCity ? [] : $this->cities->suggestions($city),
            'address' => trim((string) ($sav?->address ?: $order->shippingAddressLine())),
            'price' => $this->price($order, $options, $sav),
            'open' => array_key_exists('open', $options) ? (bool) $options['open'] : (bool) $this->settings->default_open,
            'fragile' => array_key_exists('fragile', $options) ? (bool) $options['fragile'] : (bool) $this->settings->default_fragile,
            'replace' => $kind === 'echange',
            'stock' => $this->settings->stockFor($kind),
            'nature' => $this->nature($order, $sav),
            'products' => $this->products($order, $sav),
            'note' => $this->settings->send_note ? Str::limit(trim((string) ($options['note'] ?? $order->note)), 250, '') : '',
        ];
    }

    protected function price(Order $order, array $options, ?SavRequest $sav): float
    {
        if ($sav) {
            return isset($options['price']) && is_numeric($options['price']) ? round(max(0, (float) $options['price']), 2) : 0.0;
        }

        return $order->isCod() ? round((float) $order->total_price, 2) : 0.0;
    }

    /** @return list<array{ref:string, qnty:int}> only lines that carry a real SKU. */
    protected function products(Order $order, ?SavRequest $sav): array
    {
        if (! $this->settings->send_products) {
            return [];
        }
        $lines = $sav
            ? $sav->items()->where('direction', 'deliver')->get()->map(fn ($i) => ['sku' => $i->sku, 'quantity' => $i->quantity])->all()
            : (is_array($order->line_items) ? array_values($order->line_items) : []);
        $out = [];
        foreach ($lines as $line) {
            $sku = trim((string) ($line['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $out[] = ['ref' => $sku, 'qnty' => max(1, (int) ($line['quantity'] ?? 1))];
        }

        return $out;
    }

    protected function nature(Order $order, ?SavRequest $sav): string
    {
        $mode = $this->settings->nature_mode ?: 'products';
        if ($mode === 'none') {
            return '';
        }
        if ($mode === 'fixed') {
            return Str::limit(trim((string) $this->settings->nature_text), 200, '');
        }
        $lines = $sav
            ? $sav->items()->where('direction', 'deliver')->get()->map(fn ($i) => ['title' => $i->title, 'quantity' => $i->quantity])->all()
            : (is_array($order->line_items) ? array_values($order->line_items) : []);
        $parts = [];
        foreach ($lines as $l) {
            $parts[] = max(1, (int) ($l['quantity'] ?? 1)).'× '.trim((string) ($l['title'] ?? $l['name'] ?? 'Article'));
        }
        $text = implode(', ', $parts) ?: (string) $order->productName();
        if ($sav && $sav->type === 'echange') {
            $text = 'Échange — '.$text;
        }

        return Str::limit($text, 200, '');
    }

    /**
     * add-parcel form fields. Throws an OzonException (one problem per line) when something
     * mandatory is missing. @return array{fields: array, summary: array}
     */
    public function build(Order $order, array $options = [], ?SavRequest $sav = null): array
    {
        $s = $this->summary($order, $options, $sav);
        $errors = [];
        if (! $sav) {
            if (! $order->isConfirmed()) {
                $errors[] = 'La commande doit être confirmée avant l’envoi à Ozon.';
            }
            if (in_array($order->deliveryCategory(), ['succes', 'annulation', 'retour'], true)) {
                $errors[] = "Commande au statut « {$order->deliveryStatusLabel()} » : envoi impossible.";
            }
        } elseif ($sav->type !== 'echange') {
            $errors[] = 'Seuls les échanges peuvent être envoyés à Ozon depuis Retours & échanges.';
        }
        if ($s['receiver'] === '') {
            $errors[] = 'Nom du client manquant.';
        }
        if ($s['phone'] === '') {
            $errors[] = 'Téléphone manquant.';
        }
        if ($s['city'] === '') {
            $errors[] = 'Ville manquante.';
        } elseif (! $s['ozon_city']) {
            $errors[] = $this->cities->hasCities()
                ? "Ville « {$s['city']} » non associée à une ville Ozon (Paramètres → Transporteurs → Ozon → Mapping villes)."
                : 'Liste des villes Ozon non synchronisée (Intégrations → Transporteurs → Ozon Express → Synchroniser les villes).';
        }
        if ($s['address'] === '') {
            $errors[] = 'Adresse de livraison manquante.';
        }
        if ($errors) {
            throw new OzonException(implode("\n", $errors));
        }

        $fields = [
            'parcel-receiver' => Str::limit($s['receiver'], 100, ''),
            'parcel-phone' => $s['phone'],
            'parcel-city' => (string) $s['ozon_city']['id'],
            'parcel-address' => Str::limit($s['address'], 250, ''),
            'parcel-note' => $s['note'] !== '' ? $s['note'] : null,
            'parcel-price' => self::money($s['price']),
            'parcel-nature' => $s['nature'] !== '' ? $s['nature'] : null,
            'parcel-stock' => (string) $s['stock'],
            'parcel-open' => $s['open'] ? '1' : '2',
            'parcel-fragile' => $s['fragile'] ? '1' : '0',
            'parcel-replace' => $s['replace'] ? '1' : '0',
            'products' => $s['products'] ? json_encode($s['products'], JSON_UNESCAPED_UNICODE) : null,
        ];

        return ['fields' => array_filter($fields, fn ($v) => $v !== null), 'summary' => $s];
    }

    protected static function money(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') ?: '0';
    }

    /* ------------------------------------------------------------------ create */

    /** @throws OzonException|OzonDuplicateException */
    public function createParcel(Order $order, ?User $user = null, array $options = [], ?SavRequest $sav = null): OzonShipment
    {
        // Anti-duplicate across double clicks / parallel tabs: one send at a time per order (or SAV request).
        $lock = Cache::lock('ozon-parcel-'.$order->id.'-'.($sav?->id ?? 0), 60);
        if (! $lock->get()) {
            throw new OzonException('Un envoi à Ozon est déjà en cours pour cette commande. Réessayez dans un instant.');
        }
        try {
            return $this->sendParcel($order, $user, $options, $sav);
        } finally {
            $lock->release();
        }
    }

    protected function sendParcel(Order $order, ?User $user, array $options, ?SavRequest $sav): OzonShipment
    {
        $this->assertReady();
        if ($existing = self::activeShipment($order, $sav?->id)) {
            throw new OzonDuplicateException($existing);
        }
        $built = $this->build($order, $options, $sav);
        $fields = $built['fields'];

        try {
            $result = $this->client->addParcel($fields);
        } catch (OzonException $e) {
            $this->logError('create_parcel', $e, $user, ['order_id' => $order->id, 'payload' => $fields,
                'context' => ['order_ids' => [$order->id], 'options' => $options, 'sav_request_id' => $sav?->id]]);
            throw $e;
        }
        $p = $result['parcel'];
        $s = $built['summary'];

        $shipment = OzonShipment::create([
            'company_id' => $this->settings->company_id,
            'order_id' => $order->id,
            'sav_request_id' => $sav?->id,
            'kind' => $s['kind'],
            'tracking_number' => $p['tracking_number'],
            'state' => OzonShipment::STATE_CREATED,
            'receiver' => $p['receiver'] ?? $s['receiver'],
            'phone' => $p['phone'] ?? $s['phone'],
            'city_id' => $p['city_id'] ?? $s['ozon_city']['id'],
            'city_name' => $p['city_name'] ?? $s['ozon_city']['name'],
            'address' => Str::limit((string) ($p['address'] ?? $s['address']), 500, ''),
            'price' => $p['price'] ?? $s['price'],
            'delivered_price' => $p['delivered_price'] ?? $s['ozon_city']['delivered_price'] ?? null,
            'returned_price' => $p['returned_price'] ?? $s['ozon_city']['returned_price'] ?? null,
            'refused_price' => $p['refused_price'] ?? $s['ozon_city']['refused_price'] ?? null,
            'parcel_stock' => (int) $fields['parcel-stock'],
            'parcel_open' => (int) $fields['parcel-open'],
            'parcel_fragile' => (int) $fields['parcel-fragile'],
            'parcel_replace' => (int) $fields['parcel-replace'],
            'raw_status' => $p['status'] ?? 'Nouveau Colis',
            'status_at' => now(),
            'request_payload' => $fields,
            'create_response' => $result['raw'],
            'created_by' => $user?->id,
        ]);

        if (! $sav) {
            $order->carrier = self::LABEL;
            $order->save();
        }
        $what = $sav ? "Échange {$sav->reference} envoyé à Ozon Express" : 'Envoyée à Ozon Express';
        $this->timeline($order, 'ozon_sent', $what, $user, ['ozon_shipment_id' => $shipment->id, 'sav_request_id' => $sav?->id],
            "Ville {$shipment->city_name} (#{$shipment->city_id}) · COD ".self::money((float) $shipment->price).' DH');
        $this->timeline($order, 'ozon_tracking', "Suivi Ozon créé : {$shipment->tracking_number}", $user, ['tracking_number' => $shipment->tracking_number]);

        return $shipment;
    }

    /**
     * Several orders (Commandes bulk / quick ship). Never throws: one result per order.
     *
     * @return list<array{order_id:int, reference:string, success:bool, tracking:?string, message:string, duplicate?:bool}>
     */
    public function createMany(iterable $orders, ?User $user = null, array $options = []): array
    {
        $results = [];
        foreach ($orders as $order) {
            try {
                $shipment = $this->createParcel($order, $user, $options);
                $results[] = ['order_id' => $order->id, 'reference' => $order->reference(), 'success' => true, 'tracking' => $shipment->tracking_number, 'message' => "Envoyée (n° {$shipment->tracking_number})"];
            } catch (OzonDuplicateException $e) {
                $results[] = ['order_id' => $order->id, 'reference' => $order->reference(), 'success' => false, 'tracking' => $e->shipment->tracking_number, 'message' => $e->getMessage(), 'duplicate' => true];
            } catch (OzonException $e) {
                $results[] = ['order_id' => $order->id, 'reference' => $order->reference(), 'success' => false, 'tracking' => null, 'message' => str_replace("\n", ' ', $e->getMessage())];
                if ($e->configuration) {
                    break; // wrong key / missing config: same answer for every order
                }
            } catch (Throwable $e) {
                Log::error('Ozon create failed', ['order_id' => $order->id, 'error' => $this->client->redact($e->getMessage())]);
                $results[] = ['order_id' => $order->id, 'reference' => $order->reference(), 'success' => false, 'tracking' => null, 'message' => 'Erreur inattendue lors de l’envoi à Ozon.'];
            }
        }

        return $results;
    }

    /* ------------------------------------------------------------------ parcel-info */

    /** « Actualiser depuis Ozon »: parcel-info + tracking of one parcel. @throws OzonException */
    public function refreshParcelInfo(OzonShipment $shipment, ?User $user = null): OzonShipment
    {
        $this->assertReady();
        try {
            $info = $this->client->parcelInfo((string) $shipment->tracking_number);
        } catch (OzonException $e) {
            $shipment->forceFill(['last_error' => Str::limit($e->getMessage(), 1000, '')])->save();
            $this->logError('parcel_info', $e, $user, ['order_id' => $shipment->order_id, 'ozon_shipment_id' => $shipment->id,
                'payload' => ['tracking-number' => $shipment->tracking_number], 'context' => ['shipment_id' => $shipment->id]]);
            throw $e;
        }
        $p = $info['parcel'];
        $shipment->fill(array_filter([
            'receiver' => $p['receiver'], 'phone' => $p['phone'], 'city_id' => $p['city_id'], 'city_name' => $p['city_name'],
            'address' => $p['address'] ? Str::limit($p['address'], 500, '') : null, 'price' => $p['price'], 'delivered_price' => $p['delivered_price'],
            'returned_price' => $p['returned_price'], 'refused_price' => $p['refused_price'],
        ], fn ($v) => $v !== null));
        $shipment->last_response = ['parcel_info' => $info['raw']];
        $shipment->last_error = null;
        $shipment->last_synced_at = now();
        $shipment->save();

        $this->refreshTracking($shipment, $user, 'manuel');

        return $shipment->fresh();
    }

    /* ------------------------------------------------------------------ tracking */

    /** Single status lookup (POST tracking). Returns true when the order status changed. @throws OzonException */
    public function refreshTracking(OzonShipment $shipment, ?User $user = null, string $source = 'manuel'): bool
    {
        $this->assertReady();
        try {
            $res = $this->client->tracking((string) $shipment->tracking_number);
        } catch (OzonException $e) {
            $shipment->forceFill(['last_error' => Str::limit($e->getMessage(), 1000, '')])->save();
            $this->logError('tracking', $e, $user, ['order_id' => $shipment->order_id, 'ozon_shipment_id' => $shipment->id,
                'payload' => ['tracking-number' => $shipment->tracking_number], 'context' => ['shipment_id' => $shipment->id]]);
            throw $e;
        }

        return $this->applyTracking($shipment, $res['tracking'], $source, $user);
    }

    /**
     * Bulk status sync (scheduled + « Synchroniser maintenant »): one JSON request per chunk of
     * 50, single calls for the parcels the bulk answer did not contain.
     *
     * @param  iterable<OzonShipment>  $shipments
     * @return array{checked:int, updated:int, errors:list<string>}
     */
    public function syncStatus(iterable $shipments, ?User $user = null, string $source = 'sync'): array
    {
        $byCode = [];
        foreach ($shipments as $s) {
            if ($s->tracking_number) {
                $byCode[$s->tracking_number] = $s;
            }
        }
        $stats = ['checked' => 0, 'updated' => 0, 'errors' => []];
        if ($byCode === []) {
            return $stats;
        }
        $this->assertReady();

        foreach (array_chunk(array_keys($byCode), 50) as $chunk) {
            $found = [];
            try {
                $bulk = $this->client->trackingBulk($chunk);
                $found = $bulk['trackings'];
                if (count($found) < count($chunk)) {
                    // Bulk answer shape not confirmed by Ozon docs: keep a (key-free) sample to adapt the parser.
                    Log::warning('Ozon bulk tracking: '.count($found).'/'.count($chunk).' parsed, single calls used for the rest.',
                        ['response' => Str::limit((string) json_encode($bulk['raw'], JSON_UNESCAPED_UNICODE), 4000)]);
                }
            } catch (OzonException $e) {
                $this->logError('tracking_bulk', $e, $user, ['payload' => ['tracking-number' => $chunk],
                    'context' => ['shipment_ids' => array_map(fn ($c) => $byCode[$c]->id, $chunk)]]);
                $stats['errors'][] = $e->getMessage();
                if ($e->configuration) {
                    break;
                }
            }
            foreach ($chunk as $code) {
                $shipment = $byCode[$code];
                try {
                    $changed = isset($found[$code])
                        ? $this->applyTracking($shipment, $found[$code], $source, $user)
                        : $this->refreshTracking($shipment, $user, $source);
                    $stats['checked']++;
                    $stats['updated'] += $changed ? 1 : 0;
                } catch (OzonException $e) {
                    $stats['errors'][] = "{$code} : ".$e->getMessage();
                    if ($e->configuration) {
                        break 2;
                    }
                }
            }
        }

        $message = "{$stats['checked']} colis vérifié(s), {$stats['updated']} statut(s) mis à jour".($stats['errors'] ? ', '.count($stats['errors']).' erreur(s)' : '').'.';
        $this->settings->forceFill(['last_synced_at' => now(), 'last_sync_message' => Str::limit($message, 500, '')])->save();

        return $stats;
    }

    /**
     * Stores the raw Ozon status (kept as is), adds a timeline entry when it changed and applies
     * the mapped Lav'Fast Flow status. Returns true when the order delivery status changed.
     */
    public function applyTracking(OzonShipment $shipment, array $t, string $source = 'sync', ?User $user = null): bool
    {
        $history = (array) ($shipment->history ?? []);
        $seen = [];
        foreach (array_merge($history, (array) ($t['history'] ?? [])) as $e) {
            if (is_array($e) && (($e['status'] ?? null) || ($e['time'] ?? null))) {
                $seen[($e['time'] ?? '').'|'.($e['status'] ?? '')] = $e;
            }
        }
        ksort($seen);
        $shipment->history = array_values($seen);
        $shipment->last_synced_at = now();
        $shipment->last_error = null;

        $raw = $t['status'] ?? null;
        $at = self::time($t['time'] ?? null);
        $isNew = $raw && (OzonStatusMap::key($raw) !== OzonStatusMap::key($shipment->raw_status)
            || ($at && (! $shipment->status_at || ! $at->equalTo($shipment->status_at)) && $shipment->raw_status_comment !== ($t['comment'] ?? null)));
        if (! $isNew) {
            $shipment->save();

            return false;
        }

        $this->settings->rememberStatus($raw);
        $mapped = OzonStatusMap::find($this->settings->mapping(), $raw);
        $status = $mapped ? DeliveryStatus::findByCode($mapped) : null;

        $shipment->forceFill([
            'raw_status' => Str::limit($raw, 120, ''),
            'raw_status_comment' => isset($t['comment']) ? Str::limit((string) $t['comment'], 500, '') : null,
            'status_at' => $at ?? now(),
            'mapped_status' => $status?->code,
        ]);
        if ($status) {
            $shipment->state = match ($status->category) {
                'succes' => OzonShipment::STATE_DELIVERED,
                'retour' => OzonShipment::STATE_RETURNED,
                'annulation' => OzonShipment::STATE_CANCELLED,
                default => OzonShipment::STATE_CREATED,
            };
        }
        $shipment->save();

        $order = $shipment->order;
        if (! $order) {
            return false;
        }
        $comment = $t['comment'] ?? null;
        $this->timeline($order, 'ozon_status', "Statut Ozon : {$raw}", $user, ['tracking_number' => $shipment->tracking_number, 'raw_status' => $raw, 'source' => $source],
            trim(($comment ? $comment.' · ' : '').($status ? "→ {$status->name}" : 'non associé à un statut')));

        // SAV exchange parcels never move the (already delivered) order.
        if (! $status || ! $status->is_active || $shipment->sav_request_id || $order->delivery_status === $status->code) {
            return false;
        }
        $data = [];
        if (in_array($status->category, ['echec', 'injoignable', 'annulation', 'retour'], true)) {
            $data['reason'] = Str::limit($comment ?: $raw, 250, '');
        }
        if ($status->category === 'succes') {
            $data['collected_amount'] = (float) ($shipment->price ?? ($order->isCod() ? $order->total_price : 0));
        }
        $this->workflow->applyCarrierStatus($order, $status, $data, "Ozon Express ({$source}) : {$raw}".($comment ? ' — '.Str::limit($comment, 150) : ''));

        return true;
    }

    protected static function time(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }
        try {
            return Carbon::parse($value, config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    /* ------------------------------------------------------------------ delivery notes (BL) */

    /**
     * « Créer BL Ozon »: every order must have an active Ozon tracking, then add-delivery-note
     * → add-parcel-to-delivery-note → save-delivery-note. Resumable: a failed note keeps its
     * ref and can be retried from the error log.
     *
     * @param  iterable<Order>  $orders
     *
     * @throws OzonException
     */
    public function createDeliveryNote(iterable $orders, ?User $user = null): OzonDeliveryNote
    {
        $this->assertReady();
        $shipments = [];
        $missing = [];
        foreach ($orders as $order) {
            $s = self::activeShipment($order);
            if (! $s || ! $s->tracking_number) {
                $missing[] = $order->reference();

                continue;
            }
            $shipments[] = $s;
        }
        if ($missing) {
            throw new OzonException('Sans suivi Ozon : '.implode(', ', $missing).'. Envoyez-les d’abord à Ozon.');
        }
        if ($shipments === []) {
            throw new OzonException('Sélectionnez au moins une commande envoyée à Ozon.');
        }
        $already = array_filter($shipments, fn (OzonShipment $s) => $s->delivery_note_id && $s->deliveryNote?->state === 'saved');
        if ($already) {
            throw new OzonException('Déjà dans un BL enregistré : '.implode(', ', array_map(fn ($s) => $s->tracking_number.' ('.$s->deliveryNote->ref.')', $already)).'.');
        }

        $note = OzonDeliveryNote::create(['company_id' => $this->settings->company_id, 'state' => 'creating', 'created_by' => $user?->id]);

        return $this->addToDeliveryNote($note, $shipments, $user);
    }

    /**
     * Runs the remaining steps of a delivery note (create ref if needed, add parcels, save).
     *
     * @param  OzonShipment[]  $shipments
     *
     * @throws OzonException
     */
    public function addToDeliveryNote(OzonDeliveryNote $note, array $shipments, ?User $user = null): OzonDeliveryNote
    {
        $this->assertReady();
        $responses = (array) ($note->responses ?? []);
        $context = ['delivery_note_id' => $note->id, 'order_ids' => array_map(fn ($s) => $s->order_id, $shipments)];
        $step = 'add-delivery-note';
        try {
            if (! $note->ref) {
                $res = $this->client->addDeliveryNote();
                $note->forceFill(['ref' => $res['ref'], 'state' => 'created'])->save();
                $responses['create'] = $res['raw'];
            }
            $step = 'add-parcel-to-delivery-note';
            $codes = array_map(fn (OzonShipment $s) => (string) $s->tracking_number, $shipments);
            if ($note->state !== 'saved' && $note->state !== 'filled') {
                $responses['add'] = $this->client->addParcelsToDeliveryNote($note->ref, $codes);
                foreach ($shipments as $s) {
                    OzonDeliveryNoteItem::query()->firstOrCreate(
                        ['ozon_delivery_note_id' => $note->id, 'tracking_number' => $s->tracking_number],
                        ['ozon_shipment_id' => $s->id, 'order_id' => $s->order_id]
                    );
                    $s->forceFill(['delivery_note_id' => $note->id])->save();
                    if ($s->order) {
                        $this->timeline($s->order, 'ozon_bl_added', "Ajoutée au BL Ozon {$note->ref}", $user, ['delivery_note_id' => $note->id, 'tracking_number' => $s->tracking_number]);
                    }
                }
                $note->forceFill(['state' => 'filled', 'parcels_count' => $note->items()->count()])->save();
            }
            $step = 'save-delivery-note';
            if ($note->state !== 'saved') {
                $responses['save'] = $this->client->saveDeliveryNote($note->ref);
                $note->forceFill(['state' => 'saved', 'saved_at' => now(), 'last_error' => null, 'responses' => $responses])->save();
                foreach ($shipments as $s) {
                    if ($s->order) {
                        $this->timeline($s->order, 'ozon_bl_saved', "BL Ozon {$note->ref} enregistré", $user, ['delivery_note_id' => $note->id]);
                    }
                }
            }
        } catch (OzonException $e) {
            $note->forceFill([
                'state' => $note->ref ? ($note->state === 'creating' ? 'created' : $note->state) : 'failed',
                'last_error' => Str::limit("Étape {$step} : ".$e->getMessage(), 1000, ''),
                'responses' => $responses,
            ])->save();
            $this->logError('delivery_note', $e, $user, ['ozon_delivery_note_id' => $note->id, 'order_id' => count($shipments) === 1 ? $shipments[0]->order_id : null,
                'payload' => ['Ref' => $note->ref, 'Codes' => array_map(fn ($s) => $s->tracking_number, $shipments)], 'context' => $context]);
            throw $e;
        }

        return $note->fresh('items');
    }

    /* ------------------------------------------------------------------ errors / retry */

    public function logError(string $action, OzonException $e, ?User $user = null, array $extra = []): ?OzonApiLog
    {
        if ($e->endpoint === '') {
            return null; // our own validation, not an API call
        }
        $redact = fn ($v) => $this->client->redactArray($v);

        return OzonApiLog::create([
            'company_id' => $this->settings->company_id,
            'action' => $action,
            'endpoint' => $e->endpoint,
            'order_id' => $extra['order_id'] ?? null,
            'ozon_shipment_id' => $extra['ozon_shipment_id'] ?? null,
            'ozon_delivery_note_id' => $extra['ozon_delivery_note_id'] ?? null,
            'http_status' => $e->httpStatus,
            'message' => Str::limit($this->client->redact($e->getMessage()), 1000, ''),
            'payload' => isset($extra['payload']) ? $redact($extra['payload']) : null,
            'response' => $e->response === null ? null : (is_array($e->response) ? $redact($e->response) : ['body' => $this->client->redact((string) $e->response)]),
            'context' => $extra['context'] ?? null,
            'user_id' => $user?->id,
        ]);
    }

    /** « Réessayer » from the error log. Returns a French result message. @throws OzonException */
    public function retry(OzonApiLog $log, ?User $user = null): string
    {
        $ctx = (array) ($log->context ?? []);
        $log->forceFill(['retried_at' => now()])->save();
        $message = match ($log->action) {
            'create_parcel' => $this->retryCreate($ctx, $user),
            'parcel_info' => $this->refreshParcelInfo(OzonShipment::findOrFail($ctx['shipment_id'] ?? 0), $user) ? 'Colis actualisé depuis Ozon.' : '',
            'tracking' => ($this->refreshTracking(OzonShipment::findOrFail($ctx['shipment_id'] ?? 0), $user) ? 'Statut mis à jour.' : 'Suivi vérifié, statut inchangé.'),
            'tracking_bulk' => $this->retryBulk($ctx, $user),
            'delivery_note' => $this->retryNote($ctx, $user),
            'cities' => 'Villes synchronisées : '.$this->cities->sync($this->client)['count'].'.',
            default => throw new OzonException('Cette erreur ne peut pas être relancée.'),
        };
        $log->forceFill(['resolved' => true])->save();

        return $message;
    }

    protected function retryCreate(array $ctx, ?User $user): string
    {
        $order = Order::query()->findOrFail((int) (($ctx['order_ids'] ?? [])[0] ?? 0));
        $sav = ! empty($ctx['sav_request_id']) ? SavRequest::find($ctx['sav_request_id']) : null;
        try {
            $shipment = $this->createParcel($order, $user, (array) ($ctx['options'] ?? []), $sav);
        } catch (OzonDuplicateException $e) {
            return $e->getMessage();
        }

        return "Colis créé (n° {$shipment->tracking_number}).";
    }

    protected function retryBulk(array $ctx, ?User $user): string
    {
        $shipments = OzonShipment::query()->whereIn('id', (array) ($ctx['shipment_ids'] ?? []))->with('order')->get();
        $stats = $this->syncStatus($shipments, $user, 'manuel');
        if ($stats['errors'] && ! $stats['checked']) {
            throw new OzonException(implode(' ', array_unique($stats['errors'])));
        }

        return "{$stats['checked']} colis vérifié(s), {$stats['updated']} statut(s) mis à jour.";
    }

    protected function retryNote(array $ctx, ?User $user): string
    {
        $note = OzonDeliveryNote::query()->findOrFail((int) ($ctx['delivery_note_id'] ?? 0));
        $shipments = OzonShipment::query()->whereIn('order_id', (array) ($ctx['order_ids'] ?? []))->active()->whereNull('sav_request_id')->with('order')->get()->all();
        $note = $this->addToDeliveryNote($note, $shipments, $user);

        return "BL Ozon {$note->ref} enregistré.";
    }

    /* ------------------------------------------------------------------ timeline */

    public function timeline(Order $order, string $code, string $label, ?User $user = null, array $data = [], ?string $note = null): void
    {
        OrderStatusHistory::create([
            'order_id' => $order->id,
            'kind' => 'ozon',
            'status_code' => $code,
            'status_name' => Str::limit($label, 250, ''),
            'status_color' => self::COLOR,
            'note' => $note ? Str::limit($note, 500, '') : null,
            'data' => array_filter($data + ['actor' => $user ? null : 'Système'], fn ($v) => $v !== null) ?: null,
            'user_id' => $user?->id,
        ]);
    }
}
