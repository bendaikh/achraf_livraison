<?php

namespace App\Services\Shopify;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ShopifyClient
{
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
     * Admin GraphQL API (used for the product catalog: the REST product endpoints are legacy).
     *
     * @return array<string, mixed> the "data" member
     */
    public function graphql(string $query, array $variables = []): array
    {
        $version = $this->apiVersion ?: config('services.shopify.api_version', '2025-01');
        $url = "https://{$this->shopDomain}/admin/api/{$version}/graphql.json";

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->accessToken,
            'Accept' => 'application/json',
        ])->timeout(60)->post($url, ['query' => $query, 'variables' => (object) $variables]);

        if ($response->failed()) {
            throw new RuntimeException("Shopify API error ({$response->status()}): ".mb_substr($response->body(), 0, 500));
        }
        $json = $response->json() ?? [];
        if (! empty($json['errors'])) {
            $first = is_array($json['errors']) ? ($json['errors'][0]['message'] ?? json_encode($json['errors'])) : (string) $json['errors'];
            throw new RuntimeException('Shopify GraphQL : '.$first);
        }

        return $json['data'] ?? [];
    }

    private function request(string $method, string $path, array $query = [], array $body = []): array
    {
        $version = $this->apiVersion ?: config('services.shopify.api_version', '2025-01');
        $url = "https://{$this->shopDomain}/admin/api/{$version}/".ltrim($path, '/');

        $pending = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->accessToken,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->timeout(30);

        $response = match ($method) {
            'get' => $pending->get($url, $query),
            'post' => $pending->post($url, $body),
            'delete' => $pending->delete($url),
            default => throw new RuntimeException("Unsupported HTTP method [{$method}]."),
        };

        if ($response->failed()) {
            throw new RuntimeException(
                "Shopify API error ({$response->status()}): ".$response->body()
            );
        }

        return $response->json() ?? [];
    }
}
