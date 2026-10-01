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
