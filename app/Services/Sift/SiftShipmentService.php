<?php

namespace App\Services\Sift;

use App\Models\DeliveryStatus;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Setting;
use App\Models\SiftApiLog;
use App\Models\SiftSetting;
use App\Models\SiftShipment;
use App\Models\User;
use App\Services\OrderWorkflow;
use App\Services\Speedaf\SpeedafShipmentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Commandes ⇄ Sift.ma. Public entry points (also meant for the future Automatisations engine,
 * which must call them explicitly — nothing here sends parcels on its own):
 *   createParcel(), refreshParcel(), updateParcel(), cancelParcel(), hideParcel(), syncStatus(),
 *   applyParcel() (webhooks), waybill(), resync(), searchProducts().
 * Every API failure is written to sift_api_logs (redacted) so it can be retried from the UI.
 */
class SiftShipmentService
{
    public const COLOR = '#7c3aed';

    public const LABEL = 'Sift';

    protected SiftClient $client;

    public function __construct(protected SiftSetting $settings, protected OrderWorkflow $workflow, ?SiftClient $client = null)
    {
        $this->client = $client ?? SiftClient::for($settings);
    }

    public static function for(SiftSetting $settings): self
    {
        return new self($settings, app(OrderWorkflow::class));
    }

    public static function forCompany(int $companyId): self
    {
        return self::for(SiftSetting::forCompany($companyId));
    }

    public function settings(): SiftSetting
    {
        return $this->settings;
    }

    public function client(): SiftClient
    {
        return $this->client;
    }

    /** Bulk actions (more than one order at once) stay off until the full cycle has been validated. */
    public static function bulkEnabled(): bool
    {
        return (bool) Setting::getValue('sift_bulk_enabled', false);
    }

    /** @throws SiftException */
    public function assertReady(): void
    {
        if ($missing = $this->settings->missingForShipping()) {
            throw new SiftException('Intégration Sift incomplète : '.implode(', ', $missing).' (Intégrations → Transporteurs → Sift.ma).', '', null, null, true);
        }
    }

    public static function activeShipment(Order $order): ?SiftShipment
    {
        return SiftShipment::query()->where('order_id', $order->id)->active()->latest('id')->first();
    }

    /**
     * customOrderNo = our order number. Sift answers an existing customOrderNo with the existing
     * parcel, so after a cancellation the re-send gets a suffix (-R2, -R3…) to get a new parcel.
     */
    public function customOrderNo(Order $order): string
    {
        $base = ltrim(trim($order->reference()), '#') ?: 'CMD-'.$order->id;
        $cancelled = SiftShipment::query()->where('order_id', $order->id)->where('state', SiftShipment::STATE_CANCELLED)->count();

        return $cancelled > 0 ? $base.'-R'.($cancelled + 1) : $base;
    }

    /* ------------------------------------------------------------------ build / preview */

    /** What will be sent (validation popup) + blocking errors. Never calls the API. */
    public function preview(Order $order, array $options = []): array
    {
        $existing = self::activeShipment($order);
        $errors = [];
        $built = null;
        try {
            $built = $this->build($order, $options);
        } catch (SiftException $e) {
            $errors = array_values(array_filter(explode("\n", $e->getMessage())));
        }
        $data = $built['summary'] ?? $this->summary($order, $options);

        return $data + [
            'order_id' => $order->id,
            'reference' => $order->reference(),
            'errors' => $errors,
            'already' => $existing ? $existing->toSummary() + ['tracking_number' => $existing->tracking_number ?: $existing->parcel_id] : null,
            'can_send' => ! $existing && $errors === [],
        ];
    }

    protected function summary(Order $order, array $options): array
    {
        $address = is_array($order->shipping_address) ? $order->shipping_address : [];
        $items = $this->items($order);

        return [
            'receiver' => trim((string) ($order->customer_name ?: trim(($address['first_name'] ?? '').' '.($address['last_name'] ?? '')) ?: ($address['name'] ?? ''))),
            'phone' => SpeedafShipmentService::normalizePhone($order->phone ?: ($address['phone'] ?? null)),
            'city' => trim((string) $order->shippingCity()),
            'address' => trim((string) $order->shippingAddressLine()),
            'price' => $order->isCod() ? round((float) $order->total_price, 2) : 0.0,
            'cod' => $order->isCod(),
            'open' => array_key_exists('open', $options) ? (bool) $options['open'] : (bool) $this->settings->default_allow_open,
            'items' => $items,
            'quantity' => array_sum(array_column($items, 'quantity')),
            'items_mode' => $this->settings->items_mode ?: 'manual',
            'note' => $this->settings->send_note ? Str::limit(trim((string) ($options['note'] ?? $order->note)), 250, '') : '',
            'custom_order_no' => $this->customOrderNo($order),
        ];
    }

    /**
     * Product lines. items_mode "sku": lines with a SKU are sent with it (linked to the stock
     * kept at Sift), the others as manual lines; "manual": every line as a manual line (name,
     * quantity, price), no stock link. A SKU is never invented.
     *
     * @return list<array{name:string, quantity:int, price:float, sku?:string}>
     */
    protected function items(Order $order): array
    {
        $out = [];
        $useSku = ($this->settings->items_mode ?: 'manual') === 'sku';
        foreach (is_array($order->line_items) ? array_values($order->line_items) : [] as $line) {
            $item = [
                'name' => Str::limit(trim((string) ($line['title'] ?? $line['name'] ?? 'Article')), 190, ''),
                'quantity' => max(1, (int) ($line['quantity'] ?? 1)),
                'price' => round((float) ($line['price'] ?? 0), 2),
            ];
            $sku = trim((string) ($line['sku'] ?? ''));
            if ($useSku && $sku !== '') {
                $item = ['sku' => $sku] + $item;
            }
            $out[] = $item;
        }
        if ($out === [] && $order->productName()) {
            $out[] = ['name' => Str::limit((string) $order->productName(), 190, ''), 'quantity' => max(1, (int) ($order->quantity ?? 1)), 'price' => round((float) $order->total_price, 2)];
        }

        return $out;
    }

    /**
     * POST /parcels body. Field names follow the T8 spec (no published schema, see
     * docs/integration-sift.md). Throws a SiftException (one problem per line) when something
     * mandatory is missing. @return array{payload: array, summary: array}
     */
    public function build(Order $order, array $options = []): array
    {
        $s = $this->summary($order, $options);
        $errors = [];
        if (! $order->isConfirmed()) {
            $errors[] = 'La commande doit être confirmée avant l’envoi à Sift.';
        }
        if (in_array($order->deliveryCategory(), ['succes', 'annulation', 'retour'], true)) {
            $errors[] = "Commande au statut « {$order->deliveryStatusLabel()} » : envoi impossible.";
        }
        if ($s['receiver'] === '') {
            $errors[] = 'Nom du client manquant.';
        }
        if ($s['phone'] === '') {
            $errors[] = 'Téléphone manquant.';
        }
        if ($s['city'] === '') {
            $errors[] = 'Ville manquante.';
        }
        if ($s['address'] === '') {
            $errors[] = 'Adresse de livraison manquante.';
        }
        if ($s['items'] === []) {
            $errors[] = 'Aucun produit dans la commande.';
        }
        if ($errors) {
            throw new SiftException(implode("\n", $errors));
        }

        $payload = array_filter([
            'customOrderNo' => $s['custom_order_no'],
            'customerName' => Str::limit($s['receiver'], 120, ''),
            'customerPhone' => $s['phone'],
            'address' => Str::limit($s['address'], 250, ''),
            'city' => Str::limit($s['city'], 120, ''),
            'items' => $s['items'],
            'quantity' => $s['quantity'],
            'price' => $s['price'],
            'codAmount' => $s['price'],
            'cod' => $s['cod'],
            'notes' => $s['note'] !== '' ? $s['note'] : null,
            'allowOpen' => $s['open'],
        ], fn ($v) => $v !== null);

        return ['payload' => $payload, 'summary' => $s];
    }

    /* ------------------------------------------------------------------ create */

    /** Creates the parcel (anti-duplicate: local check + lock + customOrderNo at Sift). @throws SiftException */
    public function createParcel(Order $order, ?User $user = null, array $options = []): SiftShipment
    {
        $lock = Cache::lock('sift-parcel-'.$order->id, 60);
        if (! $lock->get()) {
            throw new SiftException('Un envoi à Sift est déjà en cours pour cette commande. Réessayez dans un instant.');
        }
        try {
            return $this->sendParcel($order, $user, $options);
        } finally {
            $lock->release();
        }
    }

    protected function sendParcel(Order $order, ?User $user, array $options): SiftShipment
    {
        $this->assertReady();
        if ($existing = self::activeShipment($order)) {
            throw new SiftDuplicateException($existing);
        }
        $built = $this->build($order, $options);
        $payload = $built['payload'];

        try {
            $result = $this->client->createParcel($payload);
        } catch (SiftException $e) {
            // No answer (timeout…): the parcel may exist anyway — look it up by customOrderNo.
            if ($e->httpStatus === null && ! $e->configuration && ($found = $this->lookupQuietly($payload['customOrderNo']))) {
                $result = ['parcel' => $found, 'existing' => true, 'raw' => ['resync' => 'customOrderNo']];
            } else {
                $this->logError('create_parcel', $e, $user, ['order_id' => $order->id, 'payload' => $payload,
                    'context' => ['order_ids' => [$order->id], 'options' => $options]]);
                throw $e;
            }
        }

        $p = $result['parcel'];
        if ($result['existing'] && SiftStatusMap::key($p['status']) === 'cancelled') {
            $e = new SiftException("Sift a renvoyé un colis existant déjà annulé pour {$payload['customOrderNo']}. Contactez Sift ou modifiez le numéro de commande.", '/parcels', 200, $result['raw'], false, 'POST');
            $this->logError('create_parcel', $e, $user, ['order_id' => $order->id, 'payload' => $payload, 'context' => ['order_ids' => [$order->id], 'options' => $options]]);
            throw $e;
        }

        $shipment = SiftShipment::create([
            'company_id' => $this->settings->company_id,
            'order_id' => $order->id,
            'parcel_id' => $p['parcel_id'],
            'tracking_number' => $p['tracking_number'],
            'custom_order_no' => $p['custom_order_no'] ?? $payload['customOrderNo'],
            'state' => SiftShipment::STATE_CREATED,
            'receiver' => $p['receiver'] ?? $payload['customerName'],
            'phone' => $p['phone'] ?? $payload['customerPhone'],
            'city' => $p['city'] ?? $payload['city'],
            'address' => Str::limit((string) ($p['address'] ?? $payload['address']), 500, ''),
            'cod_amount' => $p['cod_amount'] ?? $payload['codAmount'],
            'allow_open' => $p['allow_open'] ?? $payload['allowOpen'],
            'raw_status' => 'pending',
            'status_at' => now(),
            'history' => $p['history'] ?: null,
            'reused_existing' => (bool) $result['existing'],
            'request_payload' => $payload,
            'create_response' => $result['raw'],
            'created_by' => $user?->id,
        ]);
        $order->carrier = self::LABEL;
        $order->save();
        $note = 'Ville '.$shipment->city.' · COD '.number_format((float) $shipment->cod_amount, 2, ',', ' ').' DH · customOrderNo '.$shipment->custom_order_no;
        $this->timeline($order, 'sift_sent', $result['existing'] ? 'Envoyée à Sift (colis existant renvoyé par Sift)' : 'Envoyée à Sift', $user,
            ['sift_shipment_id' => $shipment->id, 'parcel_id' => $shipment->parcel_id, 'custom_order_no' => $shipment->custom_order_no], $note);
        $this->timeline($order, 'sift_tracking', 'Suivi Sift créé : '.($shipment->tracking_number ?: $shipment->parcel_id), $user,
            ['tracking_number' => $shipment->tracking_number, 'parcel_id' => $shipment->parcel_id]);
        // Existing parcel already moving at Sift: apply its current status right away.
        if ($p['status'] && (SiftStatusMap::key($p['status']) !== 'pending' || $p['sub_status'])) {
            $this->applyParcel($shipment, $p, 'envoi', $user);
        } else {
            $this->settings->rememberStatus('pending');
        }

        return $shipment->fresh();
    }

    protected function lookupQuietly(string $customOrderNo): ?array
    {
        try {
            return $this->client->findByCustomOrderNo($customOrderNo);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<array{order_id:int, reference:string, success:bool, tracking:?string, message:string, duplicate?:bool}> */
    public function createMany(iterable $orders, ?User $user = null, array $options = []): array
    {
        $results = [];
        foreach ($orders as $order) {
            try {
                $s = $this->createParcel($order, $user, $options);
                $tn = $s->tracking_number ?: $s->parcel_id;
                $results[] = ['order_id' => $order->id, 'reference' => $order->reference(), 'success' => true, 'tracking' => $tn,
                    'message' => ($s->reused_existing ? 'Colis existant chez Sift' : 'Envoyée')." (n° {$tn})"];
            } catch (SiftDuplicateException $e) {
                $results[] = ['order_id' => $order->id, 'reference' => $order->reference(), 'success' => false, 'tracking' => $e->shipment->tracking_number, 'message' => $e->getMessage(), 'duplicate' => true];
            } catch (SiftException $e) {
                $results[] = ['order_id' => $order->id, 'reference' => $order->reference(), 'success' => false, 'tracking' => null, 'message' => str_replace("\n", ' ', $e->getMessage())];
                if ($e->configuration) {
                    break;
                }
            } catch (Throwable $e) {
                Log::error('Sift create failed', ['order_id' => $order->id, 'error' => $this->client->redact($e->getMessage())]);
                $results[] = ['order_id' => $order->id, 'reference' => $order->reference(), 'success' => false, 'tracking' => null, 'message' => 'Erreur inattendue lors de l’envoi à Sift.'];
            }
        }

        return $results;
    }

    /* ------------------------------------------------------------------ refresh / sync */

    /** « Actualiser depuis Sift »: GET /parcels/{id} (or by tracking). Returns true when the order status changed. */
    public function refreshParcel(SiftShipment $shipment, ?User $user = null, string $source = 'manuel'): bool
    {
        $this->assertReady();
        try {
            $res = $shipment->parcel_id ? $this->client->getParcel($shipment->parcel_id) : $this->client->findByTracking((string) $shipment->tracking_number);
        } catch (SiftException $e) {
            $shipment->forceFill(['last_error' => Str::limit($e->getMessage(), 1000, '')])->save();
            $this->logError('refresh', $e, $user, ['order_id' => $shipment->order_id, 'sift_shipment_id' => $shipment->id, 'context' => ['shipment_id' => $shipment->id]]);
            throw $e;
        }
        $shipment->last_response = ['parcel' => $res['raw']];

        return $this->applyParcel($shipment, $res['parcel'], $source, $user);
    }

    /**
     * Polling fallback next to webhooks (scheduled + « Synchroniser maintenant »).
     *
     * @param  iterable<SiftShipment>  $shipments
     * @return array{checked:int, updated:int, errors:list<string>}
     */
    public function syncStatus(iterable $shipments, ?User $user = null, string $source = 'sync'): array
    {
        $stats = ['checked' => 0, 'updated' => 0, 'errors' => []];
        $list = collect($shipments)->filter(fn (SiftShipment $s) => $s->parcel_id || $s->tracking_number)->values();
        if ($list->isEmpty()) {
            return $stats;
        }
        $this->assertReady();
        foreach ($list as $shipment) {
            try {
                $stats['updated'] += $this->refreshParcel($shipment, $user, $source) ? 1 : 0;
                $stats['checked']++;
            } catch (SiftException $e) {
                $stats['errors'][] = ($shipment->tracking_number ?: $shipment->parcel_id).' : '.$e->getMessage();
                if ($e->configuration) {
                    break;
                }
            }
        }
        $message = "{$stats['checked']} colis vérifié(s), {$stats['updated']} statut(s) mis à jour".($stats['errors'] ? ', '.count($stats['errors']).' erreur(s)' : '').'.';
        $this->settings->forceFill(['last_synced_at' => now(), 'last_sync_message' => Str::limit($message, 500, '')])->save();

        return $stats;
    }

    /**
     * Stores the parcel as returned by Sift (API or webhook), keeps the raw status, adds a
     * timeline entry when it changed and applies the mapped Lav'Fast Flow status.
     * Returns true when the order delivery status changed.
     */
    public function applyParcel(SiftShipment $shipment, array $p, string $source = 'sync', ?User $user = null, ?string $eventLabel = null): bool
    {
        foreach (['parcel_id', 'tracking_number', 'custom_order_no', 'receiver', 'phone', 'city', 'cod_amount', 'allow_open'] as $k) {
            if (($p[$k] ?? null) !== null && ($k !== 'parcel_id' || ! $shipment->parcel_id)) {
                $shipment->{$k} = $p[$k];
            }
        }
        if (($p['address'] ?? null) !== null) {
            $shipment->address = Str::limit($p['address'], 500, '');
        }
        $history = (array) ($shipment->history ?? []);
        $seen = [];
        foreach (array_merge($history, (array) ($p['history'] ?? [])) as $e) {
            if (is_array($e) && (($e['status'] ?? null) || ($e['time'] ?? null))) {
                $seen[($e['time'] ?? '').'|'.($e['status'] ?? '').'|'.($e['sub_status'] ?? '')] = $e;
            }
        }
        ksort($seen);
        $shipment->history = array_values($seen) ?: null;
        $shipment->last_synced_at = now();
        $shipment->last_error = null;

        $raw = $p['status'] ?? null;
        $sub = $p['sub_status'] ?? null;
        $isNew = $raw && (SiftStatusMap::key($raw) !== SiftStatusMap::key($shipment->raw_status) || $sub !== $shipment->raw_sub_status);
        if (! $isNew) {
            $shipment->save();

            return false;
        }

        $this->settings->rememberStatus($raw);
        $mapped = SiftStatusMap::find($this->settings->mapping(), $raw);
        $status = $mapped ? DeliveryStatus::findByCode($mapped) : null;
        $at = self::time($p['status_at'] ?? null);
        $shipment->forceFill([
            'raw_status' => Str::limit($raw, 120, ''),
            'raw_sub_status' => $sub ? Str::limit($sub, 120, '') : null,
            'raw_status_comment' => isset($p['comment']) ? Str::limit((string) $p['comment'], 500, '') : null,
            'status_at' => $at ?? now(),
            'mapped_status' => $status?->code,
        ]);
        if (SiftStatusMap::key($raw) === 'cancelled') {
            $shipment->state = SiftShipment::STATE_CANCELLED;
            $shipment->cancelled_at ??= now();
        } elseif ($status) {
            $shipment->state = match ($status->category) {
                'succes' => SiftShipment::STATE_DELIVERED,
                'retour' => SiftShipment::STATE_RETURNED,
                'annulation' => SiftShipment::STATE_CANCELLED,
                default => SiftShipment::STATE_CREATED,
            };
        }
        $shipment->save();

        $order = $shipment->order;
        if (! $order) {
            return false;
        }
        $label = SiftStatusMap::label($raw);
        $comment = $p['comment'] ?? null;
        $this->timeline($order, 'sift_status', ($eventLabel ?: 'Statut Sift').' : '.$label, $user,
            ['tracking_number' => $shipment->tracking_number, 'raw_status' => $raw, 'sub_status' => $sub, 'source' => $source],
            trim(($sub ? "{$sub} · " : '').($comment ? $comment.' · ' : '').($status ? "→ {$status->name}" : 'non associé à un statut')));

        if (! $status || ! $status->is_active || $order->delivery_status === $status->code) {
            return false;
        }
        $data = [];
        if (in_array($status->category, ['echec', 'injoignable', 'annulation', 'retour'], true)) {
            $data['reason'] = Str::limit($comment ?: $label, 250, '');
        }
        if ($status->category === 'succes') {
            $data['collected_amount'] = (float) ($shipment->cod_amount ?? ($order->isCod() ? $order->total_price : 0));
        }
        $this->workflow->applyCarrierStatus($order, $status, $data, "Sift ({$source}) : {$label}".($comment ? ' — '.Str::limit($comment, 150) : ''));

        return true;
    }

    /* ------------------------------------------------------------------ edit / cancel / hide */

    /** PUT /parcels/{id}: name, phone, address, city, COD, notes — pending parcels only. @throws SiftException */
    public function updateParcel(SiftShipment $shipment, array $fields, ?User $user = null): SiftShipment
    {
        $this->assertReady();
        if (! $shipment->isEditable()) {
            throw new SiftException('Modification impossible : Sift n’accepte les modifications que pour un colis « En attente ».');
        }
        $body = array_filter([
            'customerName' => isset($fields['receiver']) ? Str::limit(trim((string) $fields['receiver']), 120, '') : null,
            'customerPhone' => isset($fields['phone']) ? SpeedafShipmentService::normalizePhone((string) $fields['phone']) : null,
            'address' => isset($fields['address']) ? Str::limit(trim((string) $fields['address']), 250, '') : null,
            'city' => isset($fields['city']) ? Str::limit(trim((string) $fields['city']), 120, '') : null,
            'codAmount' => isset($fields['cod_amount']) && is_numeric($fields['cod_amount']) ? round((float) $fields['cod_amount'], 2) : null,
            'notes' => array_key_exists('notes', $fields) ? Str::limit(trim((string) $fields['notes']), 250, '') : null,
        ], fn ($v) => $v !== null && $v !== '');
        if ($body === []) {
            throw new SiftException('Aucune modification à envoyer.');
        }
        try {
            $res = $this->client->updateParcel((string) $shipment->parcel_id, $body);
        } catch (SiftException $e) {
            $this->logError('update_parcel', $e, $user, ['order_id' => $shipment->order_id, 'sift_shipment_id' => $shipment->id, 'payload' => $body,
                'context' => ['shipment_id' => $shipment->id, 'fields' => $fields]]);
            throw $e;
        }
        $shipment->forceFill(array_filter([
            'receiver' => $body['customerName'] ?? null, 'phone' => $body['customerPhone'] ?? null, 'address' => $body['address'] ?? null,
            'city' => $body['city'] ?? null, 'cod_amount' => $body['codAmount'] ?? null,
        ], fn ($v) => $v !== null) + ['last_response' => ['update' => $res['raw']], 'last_error' => null])->save();
        $labels = ['customerName' => 'nom', 'customerPhone' => 'téléphone', 'address' => 'adresse', 'city' => 'ville', 'codAmount' => 'COD', 'notes' => 'notes'];
        $this->timeline($shipment->order, 'sift_updated', 'Colis Sift modifié', $user, ['fields' => array_keys($body)],
            'Champs : '.implode(', ', array_map(fn ($k) => $labels[$k] ?? $k, array_keys($body))));

        return $shipment->fresh();
    }

    /** « Annuler chez le transporteur »: PUT status cancelled. The order can be shipped again afterwards. @throws SiftException */
    public function cancelParcel(SiftShipment $shipment, ?User $user = null, ?string $reason = null): SiftShipment
    {
        $this->assertReady();
        if ($shipment->state !== SiftShipment::STATE_CREATED || blank($shipment->parcel_id)) {
            throw new SiftException('Ce colis Sift n’est plus annulable (déjà livré, retourné ou annulé).');
        }
        try {
            $res = $this->client->cancelParcel((string) $shipment->parcel_id);
        } catch (SiftException $e) {
            $this->logError('cancel_parcel', $e, $user, ['order_id' => $shipment->order_id, 'sift_shipment_id' => $shipment->id,
                'payload' => ['status' => 'cancelled'], 'context' => ['shipment_id' => $shipment->id, 'reason' => $reason]]);
            throw $e;
        }
        $shipment->forceFill([
            'state' => SiftShipment::STATE_CANCELLED, 'raw_status' => $res['parcel']['status'] ?? 'cancelled', 'status_at' => now(),
            'cancelled_at' => now(), 'cancelled_by' => $user?->id, 'last_response' => ['cancel' => $res['raw']], 'last_error' => null,
        ])->save();
        $order = $shipment->order;
        if ($order && $order->carrier === self::LABEL) {
            $order->carrier = null;
            $order->save();
        }
        $this->timeline($order, 'sift_cancelled', 'Colis Sift annulé chez le transporteur', $user, ['parcel_id' => $shipment->parcel_id], $reason);

        return $shipment->fresh();
    }

    /**
     * « Supprimer / masquer »: DELETE /parcels/{id} is only a soft delete on Sift's side (the
     * carrier is not told to cancel), so it is refused while the parcel is still active —
     * cancel it at the carrier first. Hides the parcel from the order. @throws SiftException
     */
    public function hideParcel(SiftShipment $shipment, ?User $user = null): SiftShipment
    {
        if ($shipment->state === SiftShipment::STATE_CREATED) {
            throw new SiftException('Colis encore actif : utilisez d’abord « Annuler chez le transporteur ». La suppression Sift ne l’annule pas chez le livreur.');
        }
        $remote = 'non envoyé';
        if ($shipment->parcel_id && $this->settings->hasCredentials()) {
            try {
                $this->client->deleteParcel($shipment->parcel_id);
                $remote = 'supprimé chez Sift';
            } catch (SiftException $e) {
                if ($e->httpStatus !== 404) {
                    $this->logError('delete_parcel', $e, $user, ['order_id' => $shipment->order_id, 'sift_shipment_id' => $shipment->id, 'context' => ['shipment_id' => $shipment->id]]);
                    throw $e;
                }
                $remote = 'déjà absent chez Sift';
            }
        }
        $shipment->forceFill(['hidden_at' => now(), 'hidden_by' => $user?->id])->save();
        $this->timeline($shipment->order, 'sift_hidden', 'Colis Sift supprimé / masqué localement', $user, ['parcel_id' => $shipment->parcel_id], ucfirst($remote).'.');

        return $shipment->fresh();
    }

    /* ------------------------------------------------------------------ labels / resync / products */

    /** GET /parcels/{id}/waybill → PDF bytes. @throws SiftException */
    public function waybill(SiftShipment $shipment, ?string $format = null, ?User $user = null): string
    {
        $this->assertReady();
        $format = array_key_exists((string) $format, SiftSetting::WAYBILL_FORMATS) ? $format : ($this->settings->waybill_format ?: 'STANDARD_100x100');
        if (blank($shipment->parcel_id)) {
            throw new SiftException('Colis Sift sans parcelId : actualisez-le depuis Sift avant d’imprimer l’étiquette.');
        }
        try {
            return $this->client->waybill($shipment->parcel_id, $format);
        } catch (SiftException $e) {
            $this->logError('waybill', $e, $user, ['order_id' => $shipment->order_id, 'sift_shipment_id' => $shipment->id, 'payload' => ['format' => $format]]);
            throw $e;
        }
    }

    /**
     * Resync: finds the parcel at Sift by customOrderNo (GET /parcels?customOrderNo=) and links it
     * to the order when the local record is missing (lost answer, parcel created in Sift UI…).
     *
     * @throws SiftException
     */
    public function resync(Order $order, ?User $user = null): ?SiftShipment
    {
        $this->assertReady();
        if ($existing = self::activeShipment($order)) {
            $this->refreshParcel($existing, $user, 'manuel');

            return $existing->fresh();
        }
        $no = $this->customOrderNo($order);
        try {
            $p = $this->client->findByCustomOrderNo($no);
        } catch (SiftException $e) {
            $this->logError('list', $e, $user, ['order_id' => $order->id, 'payload' => ['customOrderNo' => $no]]);
            throw $e;
        }
        if (! $p || SiftStatusMap::key($p['status']) === 'cancelled') {
            return null;
        }
        $shipment = SiftShipment::create([
            'company_id' => $this->settings->company_id, 'order_id' => $order->id, 'parcel_id' => $p['parcel_id'], 'tracking_number' => $p['tracking_number'],
            'custom_order_no' => $p['custom_order_no'] ?? $no, 'state' => SiftShipment::STATE_CREATED, 'receiver' => $p['receiver'], 'phone' => $p['phone'],
            'city' => $p['city'], 'address' => $p['address'] ? Str::limit($p['address'], 500, '') : null, 'cod_amount' => $p['cod_amount'],
            'allow_open' => $p['allow_open'], 'raw_status' => null, 'reused_existing' => true, 'created_by' => $user?->id,
        ]);
        $order->carrier = self::LABEL;
        $order->save();
        $this->timeline($order, 'sift_tracking', 'Colis Sift rattaché (customOrderNo '.$shipment->custom_order_no.')', $user,
            ['tracking_number' => $shipment->tracking_number, 'parcel_id' => $shipment->parcel_id]);
        $this->applyParcel($shipment, $p, 'manuel', $user);

        return $shipment->fresh();
    }

    /** GET /products (preparation only). @throws SiftException */
    public function searchProducts(?string $search = null, ?User $user = null): array
    {
        $this->assertReady();
        try {
            return $this->client->products($search);
        } catch (SiftException $e) {
            $this->logError('products', $e, $user, ['payload' => ['search' => $search]]);
            throw $e;
        }
    }

    /* ------------------------------------------------------------------ errors / retry */

    public function logError(string $action, SiftException $e, ?User $user = null, array $extra = []): ?SiftApiLog
    {
        if ($e->endpoint === '') {
            return null; // our own validation, not an API call
        }
        $redact = fn ($v) => $this->client->redactArray($v);

        return SiftApiLog::create([
            'company_id' => $this->settings->company_id,
            'action' => $action,
            'endpoint' => $e->endpoint,
            'method' => $e->method ?: null,
            'order_id' => $extra['order_id'] ?? null,
            'sift_shipment_id' => $extra['sift_shipment_id'] ?? null,
            'http_status' => $e->httpStatus,
            'message' => Str::limit($this->client->redact($e->getMessage()), 1000, ''),
            'payload' => isset($extra['payload']) ? $redact($extra['payload']) : null,
            'response' => $e->response === null ? null : (is_array($e->response) ? $redact($e->response) : ['body' => $this->client->redact((string) $e->response)]),
            'context' => $extra['context'] ?? null,
            'user_id' => $user?->id,
        ]);
    }

    /** « Réessayer » from the error log. Returns a French result message. @throws SiftException */
    public function retry(SiftApiLog $log, ?User $user = null): string
    {
        $ctx = (array) ($log->context ?? []);
        $log->forceFill(['retried_at' => now()])->save();
        $shipment = fn () => SiftShipment::query()->findOrFail((int) ($ctx['shipment_id'] ?? 0));
        $message = match ($log->action) {
            'create_parcel' => $this->retryCreate($ctx, $user),
            'refresh' => $this->refreshParcel($shipment(), $user) ? 'Statut mis à jour.' : 'Colis actualisé, statut inchangé.',
            'update_parcel' => $this->updateParcel($shipment(), (array) ($ctx['fields'] ?? []), $user) ? 'Colis modifié chez Sift.' : '',
            'cancel_parcel' => $this->cancelParcel($shipment(), $user, $ctx['reason'] ?? null) ? 'Colis annulé chez le transporteur.' : '',
            'sync' => $this->retrySync($ctx, $user),
            default => throw new SiftException('Cette erreur ne peut pas être relancée.'),
        };
        $log->forceFill(['resolved' => true])->save();

        return $message;
    }

    protected function retryCreate(array $ctx, ?User $user): string
    {
        $order = Order::query()->findOrFail((int) (($ctx['order_ids'] ?? [])[0] ?? 0));
        try {
            $s = $this->createParcel($order, $user, (array) ($ctx['options'] ?? []));
        } catch (SiftDuplicateException $e) {
            return $e->getMessage();
        }

        return 'Colis créé (n° '.($s->tracking_number ?: $s->parcel_id).').';
    }

    protected function retrySync(array $ctx, ?User $user): string
    {
        $stats = $this->syncStatus(SiftShipment::query()->whereIn('id', (array) ($ctx['shipment_ids'] ?? []))->with('order')->get(), $user, 'manuel');
        if ($stats['errors'] && ! $stats['checked']) {
            throw new SiftException(implode(' ', array_unique($stats['errors'])));
        }

        return "{$stats['checked']} colis vérifié(s), {$stats['updated']} statut(s) mis à jour.";
    }

    /* ------------------------------------------------------------------ timeline */

    public function timeline(?Order $order, string $code, string $label, ?User $user = null, array $data = [], ?string $note = null): void
    {
        if (! $order) {
            return;
        }
        OrderStatusHistory::create([
            'order_id' => $order->id,
            'kind' => 'sift',
            'status_code' => $code,
            'status_name' => Str::limit($label, 250, ''),
            'status_color' => self::COLOR,
            'note' => $note ? Str::limit($note, 500, '') : null,
            'data' => array_filter($data + ['actor' => $user ? null : 'Système'], fn ($v) => $v !== null) ?: null,
            'user_id' => $user?->id,
        ]);
    }

    protected static function time(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }
        try {
            return is_numeric($value) ? Carbon::createFromTimestamp(strlen($value) > 10 ? (int) ($value / 1000) : (int) $value) : Carbon::parse($value)->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }
}
