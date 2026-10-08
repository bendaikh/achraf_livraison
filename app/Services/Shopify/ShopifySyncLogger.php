<?php

namespace App\Services\Shopify;

use App\Models\ShopifySyncLog;
use App\Models\ShopifyFieldChange;

/** Journal of Shopify sync attempts. Excerpts are truncated and never contain tokens. */
class ShopifySyncLogger
{
    public function log(array $attributes): ShopifySyncLog
    {
        $attributes['request_excerpt'] = $this->excerpt($attributes['request_excerpt'] ?? null);
        $attributes['response_excerpt'] = $this->excerpt($attributes['response_excerpt'] ?? null);
        $attributes['error'] = isset($attributes['error']) ? mb_substr((string) $attributes['error'], 0, 2000) : null;

        return ShopifySyncLog::create($attributes);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function fields(
        int $companyId,
        ?int $shopId,
        string $entityType,
        int $entityId,
        array $before,
        array $after,
        string $source,
        ?int $syncLogId,
        bool $conflict,
        ?int $userId = null,
    ): void {
        foreach ($after as $field => $new) {
            $old = $before[$field] ?? null;
            if ($this->same($old, $new)) {
                continue;
            }
            ShopifyFieldChange::create([
                'company_id' => $companyId,
                'shopify_shop_id' => $shopId,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'field' => $field,
                'old_value' => $this->stringify($old),
                'new_value' => $this->stringify($new),
                'source' => $source,
                'user_id' => $userId,
                'sync_log_id' => $syncLogId,
                'conflict' => $conflict,
                'created_at' => now(),
            ]);
        }
    }

    public function excerpt(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = is_string($value) ? $value : (json_encode($value, JSON_UNESCAPED_UNICODE) ?: '');
        $text = preg_replace('/shpat_[A-Za-z0-9]+/', '[token]', $text) ?? $text;
        $text = preg_replace('/"access_token"\s*:\s*"[^"]*"/', '"access_token":"[token]"', $text) ?? $text;
        $text = preg_replace('/"X-Shopify-Access-Token"\s*:\s*"[^"]*"/', '"X-Shopify-Access-Token":"[token]"', $text) ?? $text;

        return mb_substr($text, 0, 2000);
    }

    private function same(mixed $a, mixed $b): bool
    {
        return $this->stringify($a) === $this->stringify($b);
    }

    private function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);

        return $json === false ? null : mb_substr($json, 0, 4000);
    }
}
