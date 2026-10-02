<?php

namespace App\Services\Speedaf;

use App\Models\SpeedafSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Speedaf Open API client (PDF "Open API", v2 endpoints).
 *
 * Every call: POST {base}/open-api/...?appCode=XXX&timestamp=<ms>, body {"data": <payload>}.
 * Authentication = appCode + a fresh millisecond timestamp (expired timestamps → 70502).
 * The secretKey is only used to verify the HMAC-SHA256 signature of the webhook pushes.
 * Pure Laravel Http client: no proc_open / CLI tool needed (Hostinger compatible).
 */
class SpeedafClient
{
    public const PATH_CREATE_ORDER = 'open-api/express/order/v2/createOrder';

    public const PATH_CANCEL_ORDER = 'open-api/express/order/v2/cancelOrder';

    public const PATH_UPDATE_ORDER = 'open-api/express/order/v2/updateOrder';

    public const PATH_PRINT = 'open-api/express/order/v2/print';

    public const PATH_TRACK = 'open-api/express/track/v2/query';

    public const PATH_WEBHOOK_SUBSCRIBE = 'open-api/express/track/webhook/subscribe';

    public const PATH_AREA = 'open-api/common/area/v2/new/getArea';

    public const PATH_AREA_TREE = 'open-api/common/area/v2/getTreeByCountryCode';

    public const PATH_FEE = 'open-api/fee/v2/getFee';

    /** General error codes of the PDF (§2.1.3), in French. */
    public const ERROR_MESSAGES = [
        '70001' => 'Format de la requête invalide.',
        '70101' => 'Données métier manquantes dans la requête (champ data).',
        '70103' => 'Erreur de chiffrement des données de la requête : vérifiez la méthode de chiffrement avec Speedaf.',
        '70201' => 'Speedaf n’a pas pu chiffrer la réponse.',
        '70301' => 'Signature manquante dans la requête (champ sign).',
        '70302' => 'Signature invalide : vérifiez les règles de signature avec Speedaf.',
        '70401' => 'App Code invalide : vérifiez-le ou contactez Speedaf.',
        '70402' => 'App Code désactivé : contactez Speedaf.',
        '70501' => 'Horodatage (timestamp) manquant dans la requête.',
        '70502' => 'Horodatage (timestamp) expiré : vérifiez l’horloge du serveur.',
        '70601' => 'L’adresse IP du serveur est sur liste noire Speedaf : contactez Speedaf.',
        '70602' => 'L’adresse IP du serveur n’est pas dans la liste blanche Speedaf : contactez Speedaf pour l’ajouter.',
        '70801' => 'Accès non autorisé à cette interface : contactez Speedaf.',
    ];

    protected ?int $fixedTimestamp = null;

    public function __construct(protected SpeedafSetting $settings) {}

    public static function for(SpeedafSetting $settings): self
    {
        return new self($settings);
    }

    public function settings(): SpeedafSetting
    {
        return $this->settings;
    }

    /** For tests / reproducible URLs. */
    public function withTimestamp(?int $timestamp): self
    {
        $this->fixedTimestamp = $timestamp;

        return $this;
    }

    public function timestamp(): int
    {
        return $this->fixedTimestamp ?? (int) floor(microtime(true) * 1000);
    }

    public function url(string $path): string
    {
        return rtrim($this->settings->baseUrl(), '/').'/'.ltrim($path, '/').'?'.http_build_query([
            'appCode' => (string) $this->settings->app_code,
            'timestamp' => $this->timestamp(),
        ]);
    }

    /** JSON body exactly as documented: {"data": ...} (unescaped unicode/slashes). */
    public static function body(mixed $data): string
    {
        return json_encode(['data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Calls an endpoint and returns the business "data" (unwrapping the nested
     * {"success","error","data"} envelope some endpoints return, e.g. getArea).
     *
     * @throws SpeedafException
     */
    public function call(string $path, mixed $data): mixed
    {
        if (blank($this->settings->app_code)) {
            throw new SpeedafException('App Code Speedaf manquant : renseignez-le dans Intégrations → Speedaf.');
        }

        try {
            $response = Http::timeout(30)
                ->connectTimeout(10)
                ->acceptJson()
                ->withBody(self::body($data), 'application/json')
                ->post($this->url($path));
        } catch (ConnectionException $e) {
            Log::warning('Speedaf connection error', ['path' => $path, 'error' => $e->getMessage()]);

            throw new SpeedafException('Impossible de joindre l’API Speedaf ('.$this->settings->baseUrl().'). Vérifiez la connexion Internet du serveur ou réessayez plus tard.');
        }

        return $this->handle($response, $path);
    }

    protected function handle(Response $response, string $path): mixed
    {
        $json = $response->json();

        if (! is_array($json)) {
            $status = $response->status();
            Log::warning('Speedaf invalid response', ['path' => $path, 'status' => $status, 'body' => mb_substr($response->body(), 0, 500)]);
            $hint = $status === 403 ? ' Accès refusé (IP non autorisée ?).' : '';

            throw new SpeedafException("Réponse invalide de Speedaf (HTTP {$status}).{$hint}");
        }

        if (($json['success'] ?? false) !== true) {
            throw $this->exceptionFrom($json);
        }

        $data = $json['data'] ?? null;
        // Nested envelope: {"success":true,"data":{"success":true,"error":null,"data":[...]}}
        if (is_array($data) && array_key_exists('success', $data) && array_key_exists('data', $data) && array_key_exists('error', $data)) {
            if ($data['success'] !== true) {
                throw $this->exceptionFrom($data);
            }
            $data = $data['data'];
        }

        return $data;
    }

    public function exceptionFrom(array $json): SpeedafException
    {
        $error = $json['error'] ?? null;
        $code = is_array($error) ? (string) ($error['code'] ?? '') : '';
        $message = is_array($error) ? (string) ($error['message'] ?? '') : (is_string($error) ? $error : '');

        return new SpeedafException(self::translateError($code, $message), $code !== '' ? $code : null, $json);
    }

    public static function translateError(?string $code, ?string $message): string
    {
        $code = (string) $code;
        if (isset(self::ERROR_MESSAGES[$code])) {
            return 'Speedaf : '.self::ERROR_MESSAGES[$code]." (code {$code})";
        }
        $message = trim((string) $message);
        if ($message !== '') {
            return 'Speedaf a refusé la demande : '.self::translateFieldErrors($message).($code !== '' ? " (code {$code})" : '');
        }

        return 'Speedaf a refusé la demande'.($code !== '' ? " (code {$code})" : '').'.';
    }

    /** "sendName: Send name is null!; ..." → "nom de l’expéditeur manquant ; ..." */
    protected static function translateFieldErrors(string $message): string
    {
        $labels = [
            'sendName' => 'nom de l’expéditeur', 'sendAddress' => 'adresse de l’expéditeur', 'sendMobile' => 'téléphone de l’expéditeur',
            'sendCountryCode' => 'pays de l’expéditeur', 'acceptName' => 'nom du destinataire', 'acceptAddress' => 'adresse du destinataire',
            'acceptMobile' => 'téléphone du destinataire', 'acceptCountryCode' => 'pays du destinataire', 'payMethod' => 'mode de paiement',
            'transportType' => 'mode de transport', 'parcelType' => 'type de colis', 'shipType' => 'type d’expédition',
            'deliveryType' => 'mode de livraison', 'itemList' => 'liste des articles', 'piece' => 'nombre de colis',
            'platformSource' => 'platform source', 'goodsQTY' => 'quantité', 'customerCode' => 'code client',
        ];
        $parts = array_filter(array_map('trim', explode(';', $message)));
        $out = [];
        foreach ($parts as $part) {
            if (preg_match('/^(\w+)\s*:\s*.*null/i', $part, $m)) {
                $out[] = ($labels[$m[1]] ?? $m[1]).' manquant';
            } else {
                $out[] = $part;
            }
        }

        return implode(' ; ', $out);
    }

    /* ------------------------------------------------------------------ endpoints */

    /** §3.1 Create order → ['billCode' => ..., 'customerOrderNo' => ..., 'labelUrl' => ...]. */
    public function createOrder(array $order): array
    {
        $data = $this->call(self::PATH_CREATE_ORDER, $order);
        if (! is_array($data) || ($data['success'] ?? true) === false || blank($data['billCode'] ?? null)) {
            $message = is_array($data) ? ($data['message'] ?? null) : null;

            throw new SpeedafException('Speedaf n’a pas retourné de numéro de suivi'.($message ? ' : '.$message : '.'), null, is_array($data) ? $data : null);
        }

        return $data;
    }

    /** §3.2 Cancel orders → list of ['billCode','success','message']. */
    public function cancelOrders(array $billCodes, string $reason, ?string $by = null, ?string $tel = null): array
    {
        $payload = array_map(fn ($code) => array_filter([
            'customerCode' => (string) $this->settings->customer_code,
            'billCode' => (string) $code,
            'cancelReason' => $reason,
            'cancelBy' => $by,
            'cancelTel' => $tel,
        ], fn ($v) => $v !== null && $v !== ''), array_values($billCodes));

        return (array) $this->call(self::PATH_CANCEL_ORDER, $payload);
    }

    /** §3.3 Update order (before pickup) → list of ['billCode','success','message']. */
    public function updateOrders(array $orders): array
    {
        return (array) $this->call(self::PATH_UPDATE_ORDER, array_values($orders));
    }

    /** §4.1 Waybill printing → ['urls' => [...], 'orderLabels' => [...]]. */
    public function printLabels(array $billCodes, ?int $labelType = null, ?bool $withLogo = null): array
    {
        return (array) $this->call(self::PATH_PRINT, [
            'waybillNoList' => array_values($billCodes),
            'labelType' => $labelType ?? (int) ($this->settings->label_type ?: 2),
            'withLogo' => $withLogo ?? (bool) $this->settings->label_with_logo,
        ]);
    }

    /** §5.1 Tracking query → list of ['mailNo','tracks'=>[...]]. */
    public function track(array $billCodes): array
    {
        return (array) $this->call(self::PATH_TRACK, ['mailNoList' => array_values($billCodes)]);
    }

    /** §5.4 Webhook subscription (one active callbackUrl per customerCode). */
    public function subscribeWebhook(string $callbackUrl): array
    {
        return (array) $this->call(self::PATH_WEBHOOK_SUBSCRIBE, [
            'customerCode' => (string) $this->settings->customer_code,
            'callbackUrl' => $callbackUrl,
            'appCode' => (string) $this->settings->app_code,
        ]);
    }

    /** §6.1 Sub-regions (type 1=province, 2=city, 3=district). */
    public function areas(string $countryCode, int $type = 1, string $parentCode = ''): array
    {
        return (array) $this->call(self::PATH_AREA, ['countryCode' => $countryCode, 'parentCode' => $parentCode, 'type' => $type]);
    }

    /** §6.2 Full country → province → city → district tree. */
    public function areaTree(string $countryCode): array
    {
        return (array) $this->call(self::PATH_AREA_TREE, ['countryCode' => $countryCode]);
    }

    /** §7.1 Published rate inquiry. */
    public function fee(array $query): array
    {
        return (array) $this->call(self::PATH_FEE, $query);
    }

    /* ------------------------------------------------------------------ webhook signature */

    /** HMAC-SHA256(secretKey, timestamp + "\n" + rawBody), formatted "hmac-sha256=<lowercase hex>". */
    public static function webhookSignature(string $secretKey, string|int $timestamp, string $rawBody): string
    {
        return 'hmac-sha256='.hash_hmac('sha256', $timestamp."\n".$rawBody, $secretKey);
    }

    public static function verifyWebhookSignature(string $secretKey, string|int|null $timestamp, string $rawBody, ?string $signature): bool
    {
        if ($secretKey === '' || $timestamp === null || $timestamp === '' || ! $signature) {
            return false;
        }
        $expected = self::webhookSignature($secretKey, $timestamp, $rawBody);
        $given = strtolower(trim($signature));
        // Accept the raw hex as well, in case the prefix is omitted.
        if (! str_starts_with($given, 'hmac-sha256=')) {
            $given = 'hmac-sha256='.$given;
        }

        return hash_equals($expected, $given);
    }
}
