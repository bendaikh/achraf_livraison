<?php

namespace App\Services\Shopify;

class ShopifyShopDomain
{
    public static function normalize(string $shop): string
    {
        $shop = strtolower(trim($shop));
        $shop = preg_replace('#^https?://#', '', $shop) ?? $shop;
        $shop = rtrim($shop, '/');
        $shop = explode('/', $shop)[0];

        if (! str_contains($shop, '.')) {
            $shop .= '.myshopify.com';
        }

        return $shop;
    }

    public static function isValid(string $shop): bool
    {
        $normalized = self::normalize($shop);

        return (bool) preg_match('/^[a-z0-9][a-z0-9\-]*\.myshopify\.com$/', $normalized);
    }
}
