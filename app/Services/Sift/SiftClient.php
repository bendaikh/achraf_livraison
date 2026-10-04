<?php

namespace App\Services\Sift;

use App\Models\SiftSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sift.ma integration API (https://apis.sift.ma/v1, alias /api/integration/v1).
 *
 * Confirmed on 04/10/2026 (see docs/integration-sift.md): JSON envelope {"success", "data"} /
 * {"success":false, "error", "message"}; API key in "Authorization: Bearer" or "X-API-Key";
 * routes /parcels (GET, POST), /parcels/{id} (GET, PUT, DELETE), /parcels/{id}/waybill (GET),
 * /parcels/tracking/{trackingNumber} (GET), /products (GET), /webhooks (GET, POST),
 * /webhooks/{id} (GET, PATCH, DELETE). Payload field names are not published: requests use
 * the names of the spec (customOrderNo, parcelId, trackingNumber…) and responses are parsed
 * defensively. Every message / payload leaving this class goes through redact().
 */
class SiftClient
{
    public const BASE_URL = 'https://apis.sift.ma/v1';

    public const TIMEOUT = 25;

    public function __construct(
        protected ?string $apiKey = null,
        protected string $baseUrl = self::BASE_URL,
        protected string $authMode = 'bearer',
    ) {
        $this->baseUrl = rtrim($baseUrl ?: self::BASE_URL, '/');
    }

    public static function for(SiftSetting $settings): self
    {
        return new self($settings->api_key, (string) ($settings->base_url ?: self::BASE_URL), (string) ($settings->auth_mode ?: 'bearer'));
    }

    /* ------------------------------------------------------------------ parcels */

    /** « Tester la connexion »: GET /parcels?limit=1 (401 ⇒ wrong key). */
    public function checkCredentials(): array
    {
        $json = $this->request('GET', '/parcels', ['page' => 1, 'limit' => 1]);
        $list = $this->listFrom($json);

        return ['total' => $list['pagination']['total'] ?? null, 'raw' => $this->redactArray($json)];
    }

    /**
     * POST /parcels. When customOrderNo already exists Sift answers with the existing parcel
     * (anti-duplicate): `existing` is true when the answer says so (flag, HTTP 200 instead of
     * 201, or HTTP 409 carrying the parcel).
     *
     * @return array{parcel: array, existing: bool, raw: array}
     */
    public function createParcel(array $payload): array
    {
        try {
            [$json, $status] = $this->requestWithStatus('POST', '/parcels', [], $payload);
        } catch (SiftException $e) {
            // 409 Conflict that still carries the parcel = existing customOrderNo.
            if ($e->httpStatus === 409 && is_array($e->response) && ($p = self::normaliseParcel($this->unwrap($e->response))) && ($p['parcel_id'] || $p['tracking_number'])) {
                return ['parcel' => $p, 'existing' => true, 'raw' => $e->response];
            }
            throw $e;
        }
        $data = $this->unwrap($json);
        $parcel = self::normaliseParcel($data);
        if (! $parcel['parcel_id'] && ! $parcel['tracking_number']) {
            throw new SiftException('Réponse Sift sans parcelId ni trackingNumber.', '/parcels', $status, $this->redactArray($json), false, 'POST');
        }
        $flags = [$json['existing'] ?? null, $json['duplicate'] ?? null, $data['existing'] ?? null, $data['duplicate'] ?? null, $data['alreadyExists'] ?? null, $json['alreadyExists'] ?? null];
        $existing = in_array(true, $flags, true) || $status === 200 && ($json['created'] ?? $data['created'] ?? null) === false;

        return ['parcel' => $parcel, 'existing' => $existing, 'raw' => $this->redactArray($json)];
    }

    /** GET /parcels/{id}. @return array{parcel: array, raw: array} */
    public function getParcel(string $parcelId): array
    {
        $json = $this->request('GET', '/parcels/'.rawurlencode($parcelId));

        return ['parcel' => self::normaliseParcel($this->unwrap($json)), 'raw' => $this->redactArray($json)];
    }

    /** GET /parcels/tracking/{trackingNumber}. @return array{parcel: array, raw: array} */
    public function findByTracking(string $tracking): array
    {
        $json = $this->request('GET', '/parcels/tracking/'.rawurlencode($tracking));
        $parcel = self::normaliseParcel($this->unwrap($json));
        $parcel['tracking_number'] ??= $tracking;

        return ['parcel' => $parcel, 'raw' => $this->redactArray($json)];
    }

    /** PUT /parcels/{id} — name, phone, address, city, COD, notes (pending parcels only). */
    public function updateParcel(string $parcelId, array $fields): array
    {
        $json = $this->request('PUT', '/parcels/'.rawurlencode($parcelId), [], $fields);

        return ['parcel' => self::normaliseParcel($this->unwrap($json)), 'raw' => $this->redactArray($json)];
    }

    /** « Annuler chez le transporteur »: PUT /parcels/{id} {"status": "cancelled"}. */
    public function cancelParcel(string $parcelId): array
    {
        return $this->updateParcel($parcelId, ['status' => 'cancelled']);
    }

    /** DELETE /parcels/{id}: soft delete on Sift's side only — the carrier is NOT told to cancel. */
    public function deleteParcel(string $parcelId): array
    {
        return $this->redactArray($this->request('DELETE', '/parcels/'.rawurlencode($parcelId)));
    }

    /**
     * GET /parcels with pagination and filters (status, city, search, customOrderNo).
     *
     * @return array{items: list<array>, pagination: array, raw: array}
     */
    public function listParcels(array $filters = []): array
    {
        $query = array_filter([
            'page' => max(1, (int) ($filters['page'] ?? 1)),
            'limit' => min(100, max(1, (int) ($filters['limit'] ?? 20))),
            'status' => $filters['status'] ?? null,
            'city' => $filters['city'] ?? null,
            'search' => $filters['search'] ?? null,
            'customOrderNo' => $filters['customOrderNo'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
        $json = $this->request('GET', '/parcels', $query);
        $list = $this->listFrom($json);

        return $list + ['raw' => $this->redactArray($json)];
    }

    /** Parcel with exactly this customOrderNo (resync after a lost answer), or null. */
    public function findByCustomOrderNo(string $customOrderNo): ?array
    {
        foreach ($this->listParcels(['customOrderNo' => $customOrderNo, 'limit' => 10])['items'] as $p) {
            if ($p['custom_order_no'] !== null && strcasecmp($p['custom_order_no'], $customOrderNo) === 0) {
                return $p;
            }
        }

        return null;
    }

    /** GET /parcels/{id}/waybill?format=… → PDF bytes. */
    public function waybill(string $parcelId, string $format): string
    {
        $endpoint = '/parcels/'.rawurlencode($parcelId).'/waybill';
        $this->assertKey($endpoint, 'GET');
        try {
            $response = $this->http()->accept('application/pdf, application/json')->get($this->baseUrl.$endpoint, ['format' => $format]);
        } catch (ConnectionException $e) {
            throw new SiftException('Sift.ma injoignable : '.$this->redact($e->getMessage()), $endpoint, null, null, false, 'GET');
        }
        $body = (string) $response->body();
        if ($response->successful() && str_starts_with(ltrim($body), '%PDF')) {
            return $body;
        }
        $json = json_decode($body, true);
        if ($response->successful() && is_array($json)) {
            // Some APIs return the PDF base64-encoded or as a URL inside the JSON envelope.
            $data = $this->unwrap($json);
            foreach (['pdf', 'base64', 'file', 'content'] as $k) {
                if (is_string($data[$k] ?? null) && ($pdf = base64_decode((string) preg_replace('#^data:application/pdf;base64,#', '', $data[$k]), true)) && str_starts_with($pdf, '%PDF')) {
                    return $pdf;
                }
            }
        }
        $this->decode($response, $endpoint, 'GET'); // throws with the API message when failed

        throw new SiftException('Étiquette Sift illisible (PDF attendu).', $endpoint, $response->status(), is_array($json) ? $this->redactArray($json) : null, false, 'GET');
    }

    /* ------------------------------------------------------------------ products / webhooks */

    /** GET /products?search= (preparation only: Shopify stays the catalog). */
    public function products(?string $search = null, int $limit = 20): array
    {
        $json = $this->request('GET', '/products', array_filter(['search' => $search, 'limit' => $limit, 'page' => 1]));
        $list = $this->listFrom($json);
        $items = array_map(fn ($p) => [
            'id' => self::str($p['id'] ?? $p['_id'] ?? $p['productId'] ?? null),
            'sku' => self::str($p['sku'] ?? $p['SKU'] ?? $p['reference'] ?? null),
            'name' => self::str($p['name'] ?? $p['title'] ?? null),
            'stock' => is_numeric($p['stock'] ?? $p['quantity'] ?? $p['availableQuantity'] ?? null) ? (int) ($p['stock'] ?? $p['quantity'] ?? $p['availableQuantity']) : null,
            'price' => self::num($p['price'] ?? null),
        ], array_filter($list['raw_items'], 'is_array'));

        return ['items' => $items, 'pagination' => $list['pagination']];
    }

    /** POST /webhooks {url, events, secret} — body field names not published (see docs). */
    public function registerWebhook(string $url, array $events, string $secret): array
    {
        $json = $this->request('POST', '/webhooks', [], ['url' => $url, 'events' => array_values($events), 'secret' => $secret, 'active' => true]);
        $data = $this->unwrap($json);

        return ['id' => self::str($data['id'] ?? $data['_id'] ?? $data['webhookId'] ?? null), 'raw' => $this->redactArray($json)];
    }

    public function listWebhooks(): array
    {
        $json = $this->request('GET', '/webhooks');

        return ['items' => $this->listFrom($json)['raw_items'], 'raw' => $this->redactArray($json)];
    }

    /* ------------------------------------------------------------------ webhook signature */

    /**
     * Signature check of an incoming webhook. The exact scheme is not published, so the usual
     * HMAC-SHA256 variants are accepted — all keyed with the dedicated secret:
     *   hex / base64 of HMAC(rawBody), optional "sha256=" prefix;
     *   "t=<ts>,v1=<hex>" (HMAC of "<ts>.<rawBody>");
     *   timestamp header + HMAC of "<ts>.<rawBody>" or "<ts>\n<rawBody>".
     * Returns the variant name that matched, or null.
     */
    public static function verifySignature(string $secret, string $rawBody, ?string $signature, ?string $timestamp = null): ?string
    {
        $signature = trim((string) $signature);
        if ($secret === '' || $signature === '') {
            return null;
        }
        $candidates = [];
        if (preg_match('/(?:^|,)\s*t=(\d+)/', $signature, $t) && preg_match_all('/(?:^|,)\s*v1=([A-Za-z0-9+\/=]+)/', $signature, $v)) {
            foreach ($v[1] as $sig) {
                $candidates[] = ['stripe', $t[1].'.'.$rawBody, $sig];
            }
        } else {
            $sig = (string) preg_replace('/^(sha256|hmac-sha256)=/i', '', $signature);
            $candidates[] = ['body', $rawBody, $sig];
            if ($timestamp !== null && $timestamp !== '') {
                $candidates[] = ['timestamp.body', $timestamp.'.'.$rawBody, $sig];
                $candidates[] = ['timestamp\nbody', $timestamp."\n".$rawBody, $sig];
            }
        }
        foreach ($candidates as [$name, $data, $sig]) {
            $raw = hash_hmac('sha256', $data, $secret, true);
            if (hash_equals(bin2hex($raw), strtolower($sig)) || hash_equals(base64_encode($raw), $sig)) {
                return $name;
            }
        }

        return null;
    }

    /* ------------------------------------------------------------------ redaction */

    public function redact(?string $text): string
    {
        $text = (string) $text;
        $key = (string) $this->apiKey;
        if ($key !== '' && mb_strlen($key) >= 4) {
            $text = str_replace([$key, rawurlencode($key)], '••••', $text);
        }

        return (string) preg_replace('/(Bearer\s+|X-API-Key:\s*)[A-Za-z0-9._~+\/=-]{8,}/i', '$1••••', $text);
    }

    public function redactArray(mixed $data): mixed
    {
        if (is_array($data)) {
            $out = [];
            foreach ($data as $k => $v) {
                $out[$k] = in_array(strtolower((string) $k), ['authorization', 'x-api-key', 'apikey', 'api_key', 'secret', 'webhook_secret', 'webhooksecret'], true) ? '••••' : $this->redactArray($v);
            }

            return $out;
        }

        return is_string($data) ? $this->redact($data) : $data;
    }

    /* ------------------------------------------------------------------ internals */

    protected function http()
    {
        $request = Http::timeout(self::TIMEOUT)->acceptJson();

        return $this->authMode === 'x-api-key'
            ? $request->withHeaders(['X-API-Key' => (string) $this->apiKey])
            : $request->withToken((string) $this->apiKey);
    }

    protected function assertKey(string $endpoint, string $method): void
    {
        if (blank($this->apiKey)) {
            throw new SiftException('Renseignez la clé API Sift (Intégrations → Transporteurs → Sift.ma).', $endpoint, null, null, true, $method);
        }
    }

    protected function request(string $method, string $endpoint, array $query = [], ?array $body = null): array
    {
        return $this->requestWithStatus($method, $endpoint, $query, $body)[0];
    }

    /** @return array{0: array, 1: int} */
    protected function requestWithStatus(string $method, string $endpoint, array $query = [], ?array $body = null): array
    {
        $this->assertKey($endpoint, $method);
        $url = $this->baseUrl.$endpoint.($query ? '?'.http_build_query($query) : '');
        try {
            $req = $this->http();
            $response = match ($method) {
                'GET' => $req->get($url),
                'DELETE' => $req->delete($url),
                'PUT' => $req->asJson()->put($url, $body ?? []),
                default => $req->asJson()->post($url, $body ?? []),
            };
        } catch (ConnectionException $e) {
            throw new SiftException('Sift.ma injoignable : '.$this->redact($e->getMessage()), $endpoint, null, null, false, $method);
        } catch (Throwable $e) {
            throw new SiftException('Erreur d’appel Sift : '.$this->redact($e->getMessage()), $endpoint, null, null, false, $method);
        }

        return [$this->decode($response, $endpoint, $method), $response->status()];
    }

    protected function decode(Response $response, string $endpoint, string $method): array
    {
        $body = (string) $response->body();
        $json = json_decode($body, true);
        $apiMessage = is_array($json) ? (string) ($json['message'] ?? $json['error'] ?? '') : '';
        if (is_array($json) && is_array($json['errors'] ?? null)) {
            $details = [];
            foreach ($json['errors'] as $field => $err) {
                $details[] = (is_string($field) ? $field.' : ' : '').(is_array($err) ? implode(', ', array_map(fn ($x) => is_scalar($x) ? (string) $x : json_encode($x), $err)) : (string) $err);
            }
            $apiMessage = trim($apiMessage.' '.implode(' ; ', $details));
        }
        if ($response->status() === 401 || $response->status() === 403) {
            throw new SiftException('Sift : clé API refusée ('.$response->status().'). '.$this->redact($apiMessage), $endpoint, $response->status(), is_array($json) ? $this->redactArray($json) : null, true, $method);
        }
        if ($response->failed() || (is_array($json) && ($json['success'] ?? null) === false)) {
            $text = $apiMessage !== '' ? $apiMessage : mb_substr(strip_tags($body), 0, 200);
            throw new SiftException(trim('Sift : '.$this->redact($text).' (HTTP '.$response->status().')'), $endpoint, $response->status(), is_array($json) ? $this->redactArray($json) : ['body' => $this->redact(mb_substr($body, 0, 500))], false, $method);
        }
        if (! is_array($json)) {
            throw new SiftException('Réponse Sift illisible (JSON attendu).', $endpoint, $response->status(), ['body' => $this->redact(mb_substr($body, 0, 500))], false, $method);
        }

        return $json;
    }

    /** {"success":true,"data":{…}} → the parcel object (data.parcel, data, or the root). */
    protected function unwrap(array $json): array
    {
        $data = is_array($json['data'] ?? null) ? $json['data'] : $json;
        foreach (['parcel', 'item', 'result'] as $k) {
            if (is_array($data[$k] ?? null)) {
                return $data[$k];
            }
        }

        return $data;
    }

    /** @return array{items: list<array>, raw_items: list<array>, pagination: array} */
    protected function listFrom(array $json): array
    {
        $data = $json['data'] ?? $json;
        $rows = [];
        if (is_array($data) && array_is_list($data)) {
            $rows = $data;
        } elseif (is_array($data)) {
            foreach (['items', 'parcels', 'products', 'webhooks', 'results', 'docs', 'rows', 'data'] as $k) {
                if (is_array($data[$k] ?? null)) {
                    $rows = $data[$k];
                    break;
                }
            }
        }
        $meta = $json['pagination'] ?? $json['meta'] ?? (is_array($data) ? ($data['pagination'] ?? $data['meta'] ?? []) : []);
        $meta = is_array($meta) ? $meta : [];
        $rows = array_values(array_filter($rows, 'is_array'));

        return [
            'items' => array_map(fn ($r) => self::normaliseParcel($r), $rows),
            'raw_items' => $rows,
            'pagination' => [
                'page' => (int) ($meta['page'] ?? $meta['currentPage'] ?? $meta['current_page'] ?? 1),
                'limit' => (int) ($meta['limit'] ?? $meta['perPage'] ?? $meta['per_page'] ?? count($rows)),
                'total' => isset($meta['total']) || isset($meta['totalItems']) || isset($meta['totalCount']) ? (int) ($meta['total'] ?? $meta['totalItems'] ?? $meta['totalCount']) : null,
                'pages' => isset($meta['pages']) || isset($meta['totalPages']) || isset($meta['lastPage']) ? (int) ($meta['pages'] ?? $meta['totalPages'] ?? $meta['lastPage']) : null,
            ],
        ];
    }

    public static function normaliseParcel(array $p): array
    {
        $customer = is_array($p['customer'] ?? null) ? $p['customer'] : (is_array($p['recipient'] ?? null) ? $p['recipient'] : []);
        $city = $p['city'] ?? $customer['city'] ?? null;
        $history = [];
        foreach (['history', 'statusHistory', 'trackingHistory', 'events', 'tracking'] as $k) {
            if (is_array($p[$k] ?? null) && array_is_list($p[$k])) {
                foreach ($p[$k] as $e) {
                    if (! is_array($e)) {
                        continue;
                    }
                    $status = self::str($e['status'] ?? $e['state'] ?? null);
                    $time = self::str($e['date'] ?? $e['createdAt'] ?? $e['timestamp'] ?? $e['time'] ?? $e['updatedAt'] ?? null);
                    if ($status || $time) {
                        $history[] = ['status' => $status, 'sub_status' => self::str($e['subStatus'] ?? null),
                            'comment' => self::str($e['note'] ?? $e['message'] ?? $e['messageFr'] ?? $e['comment'] ?? $e['notes'] ?? null), 'time' => $time];
                    }
                }
                break;
            }
        }

        return [
            'parcel_id' => self::str($p['parcelId'] ?? $p['parcel_id'] ?? $p['id'] ?? $p['_id'] ?? null),
            'tracking_number' => self::str($p['trackingNumber'] ?? $p['tracking_number'] ?? $p['trackingNo'] ?? $p['mailNo'] ?? null),
            'custom_order_no' => self::str($p['customOrderNo'] ?? $p['custom_order_no'] ?? null),
            'status' => self::str($p['status'] ?? $p['state'] ?? null),
            'sub_status' => self::str($p['subStatus'] ?? $p['sub_status'] ?? null),
            'comment' => self::str($p['statusNote'] ?? $p['statusMessage'] ?? $p['issueReason'] ?? null),
            'status_at' => self::str($p['statusUpdatedAt'] ?? $p['lastStatusAt'] ?? $p['updatedAt'] ?? $p['updated_at'] ?? null),
            'receiver' => self::str($p['customerName'] ?? $p['recipientName'] ?? $customer['name'] ?? $p['name'] ?? null),
            'phone' => self::str($p['customerPhone'] ?? $p['recipientPhone'] ?? $customer['phone'] ?? $p['phone'] ?? null),
            'city' => is_array($city) ? self::str($city['name'] ?? null) : self::str($city),
            'address' => self::str($p['address'] ?? $customer['address'] ?? null),
            'cod_amount' => self::num($p['codAmount'] ?? $p['cod'] ?? $p['price'] ?? $p['amount'] ?? null),
            'allow_open' => isset($p['allowOpen']) ? (bool) $p['allowOpen'] : null,
            'history' => $history,
        ];
    }

    protected static function str(mixed $v): ?string
    {
        if ($v === null || is_array($v) || is_bool($v)) {
            return null;
        }
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }

    protected static function num(mixed $v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }
}
