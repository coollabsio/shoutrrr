<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Photo tags on an X post: handles are what users edit, ids are resolved once at
 * publish time and stored beside them (each lookup is a billed X API read).
 */
final class XTaggedUsers
{
    // X rejects more than 10 `tagged_user_ids` on a post.
    public const int MAX = 10;

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'targets.*.tagged_handles' => ['nullable', 'array', 'max:'.self::MAX],
            'targets.*.tagged_handles.*' => ['string', 'regex:/^@?\w{1,15}$/'],
        ];
    }

    /**
     * @param  array<mixed>  $handles
     * @return list<string> bare handles, without "@", unique ignoring case
     */
    public static function normalize(array $handles): array
    {
        $unique = [];

        foreach ($handles as $handle) {
            $bare = ltrim(trim((string) $handle), '@');

            if ($bare !== '') {
                $unique[strtolower($bare)] ??= $bare;
            }
        }

        return array_values($unique);
    }
}
