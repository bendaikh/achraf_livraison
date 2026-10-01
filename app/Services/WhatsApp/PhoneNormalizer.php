<?php

namespace App\Services\WhatsApp;

class PhoneNormalizer
{
    /**
     * Normalize to digits only (no +), suitable for WhatsApp wa_id matching.
     * Default country code: Morocco (212) when local 0XXXXXXXXX is provided.
     */
    public static function digits(?string $phone, string $defaultCountryCode = '212'): string
    {
        if ($phone === null || trim($phone) === '') {
            return '';
        }

        $raw = preg_replace('/[^\d+]/', '', $phone) ?? '';
        $hasPlus = str_starts_with($raw, '+');
        $digits = preg_replace('/\D/', '', $raw) ?? '';

        if ($digits === '') {
            return '';
        }

        if ($hasPlus) {
            return ltrim($digits, '0');
        }

        // Local Moroccan format: 06… / 07…
        if (preg_match('/^0[5-7]\d{8}$/', $digits)) {
            return $defaultCountryCode.substr($digits, 1);
        }

        // Already international without +
        if (str_starts_with($digits, $defaultCountryCode) && strlen($digits) >= 11) {
            return $digits;
        }

        // French / other: keep as-is if long enough, else prefix default
        if (strlen($digits) <= 10 && str_starts_with($digits, '0')) {
            return $defaultCountryCode.substr($digits, 1);
        }

        return $digits;
    }

    public static function matchVariants(?string $phone): array
    {
        $digits = self::digits($phone);
        if ($digits === '') {
            return [];
        }

        $variants = [$digits, '+'.$digits];

        if (str_starts_with($digits, '212') && strlen($digits) === 12) {
            $local = '0'.substr($digits, 3);
            $variants[] = $local;
            $variants[] = substr($digits, 3);
        }

        return array_values(array_unique($variants));
    }
}
