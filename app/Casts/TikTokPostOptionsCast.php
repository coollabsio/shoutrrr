<?php

declare(strict_types=1);

namespace App\Casts;

use App\Dto\Post\TikTokPostOptions;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * @implements CastsAttributes<TikTokPostOptions|null, TikTokPostOptions|array<string, mixed>|null>
 */
class TikTokPostOptionsCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?TikTokPostOptions
    {
        if (! is_string($value)) {
            return null;
        }

        $decoded = json_decode($value, true);

        return TikTokPostOptions::fromArray(is_array($decoded) ? $decoded : null);
    }

    /**
     * @param  TikTokPostOptions|array<string, mixed>|null  $value
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $options = $value instanceof TikTokPostOptions ? $value : TikTokPostOptions::fromArray($value);

        return json_encode($options->toArray(), JSON_THROW_ON_ERROR);
    }
}
