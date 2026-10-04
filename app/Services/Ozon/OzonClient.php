<?php

namespace App\Services\Ozon;

use App\Models\OzonSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Ozon Express REST API (https://api.ozonexpress.ma). Credentials travel in the URL path
 * (/customers/{ID}/{KEY}/…), so every message, payload and response that leaves this class is
 * passed through redact(): the key never reaches logs, the database or the browser.
 *
 * Response shapes (see docs/integration-ozon.md): every call returns CHECK_API {RESULT, MESSAGE}
 * plus a block per action (ADD-PARCEL.NEW-PARCEL, PARCEL-INFO.INFOS, TRACKING.LAST_TRACKING /
 * HISTORY, ADD-BL.NEW-BL.REF, ADD-PARCEL-BL, SAVE-BL). Parsing is defensive.
 */
class OzonClient
{
    public const BASE_URL = 'https://api.ozonexpress.ma';

    /** Client portal hosting the BL / label PDFs (note the double "e"). */
    public const CLIENT_URL = 'https://client.ozoneexpress.ma';

    public const TIMEOUT = 25;

    public function __construct(protected ?string $customerId = null, protected ?string $apiKey = null) {}

    public static function for(OzonSetting $settings): self
    {
        return new self($settings->customer_id, $settings->api_key);
    }

    /* ------------------------------------------------------------------ public */

    /**
     * Official city list (public endpoint, no credentials).
     *
     * @return list<array{id:int, ref:?string, name:string, delivered_price:?float, returned_price:?float, refused_price:?float}>
     */
    public function cities(): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT)->acceptJson()->get(self::BASE_URL.'/cities');
        } catch (ConnectionException $e) {
            throw new OzonException('Ozon Express injoignable : '.$this->redact($e->getMessage()), 'cities');
        }
        $json = $this->decode($response, 'cities');
        $list = $json['CITIES'] ?? $json['cities'] ?? $json;
        $out = [];
        foreach ((array) $list as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = $row['ID'] ?? $row['id'] ?? null;
            $name = trim((string) ($row['NAME'] ?? $row['name'] ?? ''));
            if (! is_numeric($id) || $name === '') {
                continue;
            }
            $out[] = [
                'id' => (int) $id,
                'ref' => isset($row['REF']) ? (string) $row['REF'] : null,
                'name' => $name,
                'delivered_price' => self::num($row['DELIVERED-PRICE'] ?? null),
                'returned_price' => self::num($row['RETURNED-PRICE'] ?? null),
                'refused_price' => self::num($row['REFUSED-PRICE'] ?? null),
            ];
        }
        if ($out === []) {
            throw new OzonException('Ozon Express n’a retourné aucune ville.', 'cities', $response->status(), $this->redactArray($json));
        }

        return $out;
    }

    /** Builds the PDF links of a saved delivery note (documented URLs, opened in the browser). */
    public static function documentUrls(string $ref): array
    {
        $r = rawurlencode($ref);

        return [
            'bl_pdf' => self::CLIENT_URL.'/pdf-delivery-note?dn-ref='.$r,
            'labels_a4' => self::CLIENT_URL.'/pdf-delivery-note-tickets?dn-ref='.$r,
            'labels_10x10' => self::CLIENT_URL.'/pdf-delivery-note-tickets-4-4?dn-ref='.$r,
        ];
    }

    /* ------------------------------------------------------------------ authenticated */

    /**
     * POST add-parcel (multipart form-data). Returns the normalised parcel + raw response.
     *
     * @return array{parcel: array, raw: array}
     */
    public function addParcel(array $fields): array
    {
        $json = $this->post('add-parcel', $fields);
        $block = $this->block($json, ['ADD-PARCEL', 'ADD_PARCEL']);
        $this->assertOk($block, 'add-parcel', $json);
        $parcel = $block['NEW-PARCEL'] ?? $block['NEW_PARCEL'] ?? $block;
        $normalised = self::normaliseParcel(is_array($parcel) ? $parcel : []);
        if (blank($normalised['tracking_number'])) {
            $normalised['tracking_number'] = self::findKey($json, ['TRACKING-NUMBER', 'TRACKING_NUMBER']);
        }
        if (blank($normalised['tracking_number'])) {
            throw new OzonException('Réponse Ozon sans numéro de suivi (TRACKING-NUMBER).', 'add-parcel', null, $this->redactArray($json));
        }

        return ['parcel' => $normalised, 'raw' => $this->redactArray($json)];
    }

    /**
     * « Tester la connexion »: there is no dedicated endpoint, so a tracking lookup of a dummy
     * number is made. CHECK_API.RESULT = ERROR ⇒ wrong ID/key (exception); anything else
     * (typically TRACKING.RESULT = ERROR "not found") ⇒ the credentials are accepted.
     */
    public function checkCredentials(): array
    {
        $json = $this->post('tracking', ['tracking-number' => 'LAVFAST-TEST-'.date('YmdHis')]);

        return $this->redactArray($json);
    }

    /** POST parcel-info {tracking-number}. @return array{parcel: array, raw: array} */
    public function parcelInfo(string $tracking): array
    {
        $json = $this->post('parcel-info', ['tracking-number' => $tracking]);
        $block = $this->block($json, ['PARCEL-INFO', 'PARCEL_INFO']);
        $this->assertOk($block, 'parcel-info', $json);
        $info = $block['INFOS'] ?? $block['INFO'] ?? $block;
        $normalised = self::normaliseParcel(is_array($info) ? $info : []);
        $normalised['tracking_number'] ??= $tracking;

        return ['parcel' => $normalised, 'raw' => $this->redactArray($json)];
    }

    /** POST tracking {tracking-number} (single, form-data). @return array{tracking: array, raw: array} */
    public function tracking(string $tracking): array
    {
        $json = $this->post('tracking', ['tracking-number' => $tracking]);
        $block = $this->block($json, ['TRACKING']);
        $this->assertOk($block, 'tracking', $json);

        return ['tracking' => self::normaliseTracking($block, $tracking), 'raw' => $this->redactArray($json)];
    }

    /**
     * POST tracking with a JSON body {"tracking-number": [...]} (bulk). Returns the trackings
     * found in the response, keyed by tracking number (missing ones are simply absent).
     *
     * @return array{trackings: array<string, array>, raw: array}
     */
    public function trackingBulk(array $trackings): array
    {
        $trackings = array_values(array_unique(array_filter(array_map('strval', $trackings))));
        $json = $this->post('tracking', ['tracking-number' => $trackings], asJson: true);
        $this->assertOk($json['CHECK_API'] ?? [], 'tracking', $json);

        $found = [];
        $wanted = array_flip($trackings);
        $walk = function ($node, ?string $parentKey) use (&$walk, &$found, $wanted) {
            if (! is_array($node)) {
                return;
            }
            $tn = $node['TRACKING-NUMBER'] ?? $node['TRACKING_NUMBER'] ?? (is_string($parentKey) && isset($wanted[$parentKey]) ? $parentKey : null);
            if (is_string($tn) && isset($wanted[$tn]) && (isset($node['LAST_TRACKING']) || isset($node['HISTORY']) || isset($node['STATUT']) || isset($node['RESULT']))) {
                if (strtoupper((string) ($node['RESULT'] ?? '')) !== 'ERROR') {
                    $found[$tn] = self::normaliseTracking($node, $tn);
                }

                return;
            }
            foreach ($node as $k => $child) {
                $walk($child, is_string($k) ? $k : null);
            }
        };
        $walk($json, null);

        return ['trackings' => $found, 'raw' => $this->redactArray($json)];
    }

    /** POST add-delivery-note → BL reference. @return array{ref: string, raw: array} */
    public function addDeliveryNote(): array
    {
        $json = $this->post('add-delivery-note', []);
        $block = $this->block($json, ['ADD-BL', 'ADD_BL']);
        $this->assertOk($block, 'add-delivery-note', $json);
        $newBl = $block['NEW-BL'] ?? $block['NEW_BL'] ?? $block;
        $ref = is_array($newBl) ? ($newBl['REF'] ?? $newBl['ref'] ?? $newBl['Ref'] ?? null) : null;
        $ref ??= self::findKey($json, ['REF', 'ref', 'Ref']);
        if (blank($ref)) {
            throw new OzonException('Ozon n’a pas retourné de référence de bon de livraison.', 'add-delivery-note', null, $this->redactArray($json));
        }

        return ['ref' => (string) $ref, 'raw' => $this->redactArray($json)];
    }

    /** POST add-parcel-to-delivery-note {Ref, Codes[]}. */
    public function addParcelsToDeliveryNote(string $ref, array $codes): array
    {
        $fields = ['Ref' => $ref];
        foreach (array_values($codes) as $i => $code) {
            $fields["Codes[{$i}]"] = (string) $code;
        }
        $json = $this->post('add-parcel-to-delivery-note', $fields);
        $this->assertOk($this->block($json, ['ADD-PARCEL-BL', 'ADD-PARCEL-TO-BL', 'ADD_PARCEL_BL']), 'add-parcel-to-delivery-note', $json);

        return $this->redactArray($json);
    }

    /** POST save-delivery-note {Ref}. */
    public function saveDeliveryNote(string $ref): array
    {
        $json = $this->post('save-delivery-note', ['Ref' => $ref]);
        $this->assertOk($this->block($json, ['SAVE-BL', 'SAVE_BL']), 'save-delivery-note', $json);

        return $this->redactArray($json);
    }

    /* ------------------------------------------------------------------ redaction */

    /** Removes the API key (and the credential path segment) from any text. */
    public function redact(?string $text): string
    {
        $text = (string) $text;
        $key = (string) $this->apiKey;
        if ($key !== '') {
            $text = str_replace([$key, rawurlencode($key), urlencode($key)], '••••', $text);
        }

        return (string) preg_replace('#/customers/([^/\s]+)/[^/\s"\'?]+#', '/customers/$1/••••', $text);
    }

    public function redactArray(mixed $data): mixed
    {
        if (is_array($data)) {
            $out = [];
            foreach ($data as $k => $v) {
                $out[$k] = $this->redactArray($v);
            }

            return $out;
        }

        return is_string($data) ? $this->redact($data) : $data;
    }

    /* ------------------------------------------------------------------ internals */

    protected function assertCredentials(string $endpoint): void
    {
        if (blank($this->customerId) || blank($this->apiKey)) {
            throw new OzonException('Renseignez l’ID client et la clé API Ozon (Intégrations → Transporteurs → Ozon Express).', $endpoint, null, null, true);
        }
    }

    protected function url(string $endpoint): string
    {
        return self::BASE_URL.'/customers/'.rawurlencode((string) $this->customerId).'/'.rawurlencode((string) $this->apiKey).'/'.$endpoint;
    }

    protected function post(string $endpoint, array $fields, bool $asJson = false): array
    {
        $this->assertCredentials($endpoint);
        try {
            $request = Http::timeout(self::TIMEOUT)->acceptJson();
            $response = $asJson
                ? $request->asJson()->post($this->url($endpoint), $fields)
                : $request->asMultipart()->post($this->url($endpoint), self::multipart($fields));
        } catch (ConnectionException $e) {
            throw new OzonException('Ozon Express injoignable : '.$this->redact($e->getMessage()), $endpoint);
        } catch (Throwable $e) {
            throw new OzonException('Erreur d’appel Ozon : '.$this->redact($e->getMessage()), $endpoint);
        }

        $json = $this->decode($response, $endpoint);
        // Credentials are checked on every call: {"CHECK_API":{"RESULT":"ERROR","MESSAGE":"Please verify your API Key"}}
        $check = $json['CHECK_API'] ?? null;
        if (is_array($check) && strtoupper((string) ($check['RESULT'] ?? '')) === 'ERROR') {
            throw new OzonException('Ozon : '.$this->redact((string) ($check['MESSAGE'] ?? 'identifiants refusés')), $endpoint, $response->status(), $this->redactArray($json), true);
        }

        return $json;
    }

    protected static function multipart(array $fields): array
    {
        $parts = [];
        foreach ($fields as $name => $value) {
            if ($value === null) {
                continue;
            }
            $parts[] = ['name' => (string) $name, 'contents' => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value];
        }

        return $parts;
    }

    protected function decode(Response $response, string $endpoint): array
    {
        $body = (string) $response->body();
        $json = json_decode($body, true);
        if ($response->failed()) {
            $message = is_array($json) ? (self::findKey($json, ['MESSAGE', 'message']) ?? '') : mb_substr(strip_tags($body), 0, 200);
            throw new OzonException(trim('Ozon a répondu HTTP '.$response->status().'. '.$this->redact($message)), $endpoint, $response->status(), is_array($json) ? $this->redactArray($json) : $this->redact(mb_substr($body, 0, 500)));
        }
        if (! is_array($json)) {
            throw new OzonException('Réponse Ozon illisible (JSON attendu).', $endpoint, $response->status(), $this->redact(mb_substr($body, 0, 500)));
        }

        return $json;
    }

    protected function block(array $json, array $keys): array
    {
        foreach ($keys as $k) {
            if (isset($json[$k]) && is_array($json[$k])) {
                return $json[$k];
            }
        }

        return $json;
    }

    protected function assertOk(array $block, string $endpoint, array $json): void
    {
        if (strtoupper((string) ($block['RESULT'] ?? '')) === 'ERROR') {
            throw new OzonException('Ozon : '.$this->redact((string) ($block['MESSAGE'] ?? 'erreur inconnue')), $endpoint, 200, $this->redactArray($json));
        }
    }

    public static function normaliseParcel(array $p): array
    {
        return [
            'tracking_number' => self::str($p['TRACKING-NUMBER'] ?? $p['TRACKING_NUMBER'] ?? null),
            'receiver' => self::str($p['RECEIVER'] ?? null),
            'phone' => self::str($p['PHONE'] ?? null),
            'city_id' => is_numeric($p['CITY_ID'] ?? $p['CITY-ID'] ?? null) ? (int) ($p['CITY_ID'] ?? $p['CITY-ID']) : null,
            'city_name' => self::str($p['CITY_NAME'] ?? $p['CITY-NAME'] ?? null),
            'address' => self::str($p['ADDRESS'] ?? null),
            'price' => self::num($p['PRICE'] ?? null),
            'delivered_price' => self::num($p['DELIVERED-PRICE'] ?? $p['DELIVERED_PRICE'] ?? null),
            'returned_price' => self::num($p['RETURNED-PRICE'] ?? $p['RETURNED_PRICE'] ?? null),
            'refused_price' => self::num($p['REFUSED-PRICE'] ?? $p['REFUSED_PRICE'] ?? null),
            'status' => self::str($p['STATUS'] ?? $p['STATUT'] ?? null),
        ];
    }

    /** {status, comment, time (Y-m-d H:i or null), history: list<{status, comment, time}>} */
    public static function normaliseTracking(array $block, string $tracking): array
    {
        $event = function ($e): ?array {
            if (! is_array($e)) {
                return null;
            }
            $status = self::str($e['STATUT'] ?? $e['STATUS'] ?? $e['STATE'] ?? null);
            $time = self::str($e['TIME_STR'] ?? $e['DATE'] ?? null);
            if (! $time && is_numeric($e['TIME'] ?? null) && (int) $e['TIME'] > 0) {
                $time = date('Y-m-d H:i', (int) $e['TIME']);
            }
            if (! $status && ! $time) {
                return null;
            }

            return ['status' => $status, 'comment' => self::str($e['COMMENT'] ?? $e['COMMENTAIRE'] ?? null), 'time' => $time];
        };

        $history = [];
        foreach ((array) ($block['HISTORY'] ?? []) as $e) {
            if ($ev = $event($e)) {
                $history[] = $ev;
            }
        }
        usort($history, fn ($a, $b) => strcmp((string) $a['time'], (string) $b['time']));

        $last = $event($block['LAST_TRACKING'] ?? null) ?? $event($block) ?? ($history ? end($history) : null);

        return [
            'tracking_number' => self::str($block['TRACKING-NUMBER'] ?? null) ?? $tracking,
            'status' => $last['status'] ?? null,
            'comment' => $last['comment'] ?? null,
            'time' => $last['time'] ?? null,
            'history' => $history,
        ];
    }

    protected static function findKey(array $data, array $keys): ?string
    {
        foreach ($data as $k => $v) {
            if (in_array($k, $keys, true) && (is_string($v) || is_numeric($v)) && (string) $v !== '') {
                return (string) $v;
            }
        }
        foreach ($data as $v) {
            if (is_array($v) && ($found = self::findKey($v, $keys)) !== null) {
                return $found;
            }
        }

        return null;
    }

    protected static function str(mixed $v): ?string
    {
        if ($v === null || is_array($v)) {
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
