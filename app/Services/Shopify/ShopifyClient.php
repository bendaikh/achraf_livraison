<?php

namespace App\Services\Shopify;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ShopifyClient
{
    /** Seconds to wait before the next call when the GraphQL bucket is low. */
    private ?int $backoffSeconds = null;

    public function __construct(
        private readonly string $shopDomain,
        private readonly string $accessToken,
        private readonly ?string $apiVersion = null,
    ) {}

    public function get(string $path, array $query = []): array
    {
        return $this->request('get', $path, query: $query);
    }

    public function post(string $path, array $body = []): array
    {
        return $this->request('post', $path, body: $body);
    }

    public function delete(string $path): array
    {
        return $this->request('delete', $path);
    }

    /**
     * Admin GraphQL API.
     *
     * @return array<string, mixed> the "data" member
     */
    public function graphql(string $query, array $variables = []): array
    {
        $this->honourBackoff();

        $version = $this->version();
        $url = "https://{$this->shopDomain}/admin/api/{$version}/graphql.json";

        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $this->accessToken,
                'Accept' => 'application/json',
            ])->timeout(60)->post($url, ['query' => $query, 'variables' => (object) $variables]);
        } catch (ConnectionException $e) {
            throw new ShopifyApiException('Shopify injoignable : '.$e->getMessage(), null, true);
        }

        if ($response->failed()) {
            throw ShopifyApiException::fromResponse($response->status(), $response->body(), $response->header('Retry-After'));
        }

        $json = $response->json() ?? [];
        $this->noteThrottle($json);

        if (! empty($json['errors'])) {
            $throttled = false;
            foreach ((array) $json['errors'] as $error) {
                if (($error['extensions']['code'] ?? null) === 'THROTTLED') {
                    $throttled = true;
                }
            }
            $first = is_array($json['errors']) ? ($json['errors'][0]['message'] ?? json_encode($json['errors'])) : (string) $json['errors'];
            throw new ShopifyApiException(
                'Shopify GraphQL : '.$first,
                $throttled ? 429 : 200,
                $throttled,
                $throttled ? ($this->backoffSeconds ?? 2) : null,
            );
        }

        return $json['data'] ?? [];
    }

    /**
     * GraphQL mutation. Throws a non-retryable exception when userErrors is not empty.
     *
     * @return array<string, mixed>
     */
    public function graphqlMutation(string $query, array $variables, string $payloadKey): array
    {
        $data = $this->graphql($query, $variables);
        $errors = $data[$payloadKey]['userErrors'] ?? [];
        if (is_array($errors) && $errors !== []) {
            $message = collect($errors)->pluck('message')->filter()->implode(' ');
            throw new ShopifyApiException(
                $message !== '' ? $message : 'Shopify a refusé la modification.',
                422,
                false,
                null,
                $errors,
            );
        }

        return $data;
    }

    private function request(string $method, string $path, array $query = [], array $body = []): array
    {
        $this->honourBackoff();

        $version = $this->version();
        $url = "https://{$this->shopDomain}/admin/api/{$version}/".ltrim($path, '/');

        $pending = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->accessToken,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->timeout(30);

        try {
            $response = match ($method) {
                'get' => $pending->get($url, $query),
                'post' => $pending->post($url, $body),
                'delete' => $pending->delete($url),
                default => throw new RuntimeException("Unsupported HTTP method [{$method}]."),
            };
        } catch (ConnectionException $e) {
            throw new ShopifyApiException('Shopify injoignable : '.$e->getMessage(), null, true);
        }

        if ($response->failed()) {
            throw ShopifyApiException::fromResponse($response->status(), $response->body(), $response->header('Retry-After'));
        }

        return $response->json() ?? [];
    }

    private function version(): string
    {
        return $this->apiVersion ?: (string) config('services.shopify.api_version', ShopifyOAuth::API_VERSION);
    }

    /** @param  array<string, mixed>  $json */
    private function noteThrottle(array $json): void
    {
        $status = $json['extensions']['cost']['throttleStatus'] ?? null;
        if (! is_array($status)) {
            return;
        }
        $available = (float) ($status['currentlyAvailable'] ?? 1000);
        $restore = (float) ($status['restoreRate'] ?? 50);
        if ($available < 100 && $restore > 0) {
            $this->backoffSeconds = (int) min(10, max(1, ceil((100 - $available) / $restore)));
        }
    }

    private function honourBackoff(): void
    {
        if ($this->backoffSeconds === null) {
            return;
        }
        $seconds = $this->backoffSeconds;
        $this->backoffSeconds = null;
        throw new ShopifyApiException(
            'Quota GraphQL Shopify bas : nouvel essai différé.',
            429,
            true,
            $seconds,
        );
    }
}
