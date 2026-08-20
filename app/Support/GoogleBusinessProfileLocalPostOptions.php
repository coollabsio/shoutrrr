<?php

declare(strict_types=1);

namespace App\Support;

final class GoogleBusinessProfileLocalPostOptions
{
    /** @return array<string, mixed> */
    public static function normalize(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        foreach (['cta_type', 'cta_url'] as $key) {
            if (! is_string($options[$key] ?? null)) {
                continue;
            }

            $value = trim($options[$key]);
            $options[$key] = $value === '' ? null : $value;
        }

        return $options;
    }

    /** @param array<string, mixed> $options */
    public static function ctaType(array $options): ?string
    {
        $type = $options['cta_type'] ?? null;

        return is_string($type) && $type !== '' ? strtoupper($type) : null;
    }

    /** @param array<string, mixed> $options */
    public static function ctaUrl(array $options): ?string
    {
        $url = $options['cta_url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }
}
