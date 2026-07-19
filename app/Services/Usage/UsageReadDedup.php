<?php

declare(strict_types=1);

namespace App\Services\Usage;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;

/**
 * Replicates X's daily per-object billing dedup without a database table: the
 * same post id read again on the same day is not billed twice. Best-effort — a
 * cache flush resets the window (small transient over-count, never under). Dedup
 * is per (workspace, platform) so cost stays attributable per workspace, which
 * intentionally does not match X's app-level dedup (documented trade-off).
 *
 * The dedup key is (workspace, platform, day, id) and intentionally omits the
 * operation, so the same id read under two different read operations on the
 * same day is billed once — whichever runs first — matching X's app-level
 * per-object dedup. Cost attribution then depends on ordering, which is
 * acceptable since the overlap (e.g. own-post metrics vs. others' replies) is
 * negligible.
 */
class UsageReadDedup
{
    /**
     * @param  list<string>  $objectIds
     */
    public function countNew(string $workspaceId, string $platform, array $objectIds): int
    {
        $now = CarbonImmutable::instance(Date::now());
        $day = $now->toDateString();
        $expiresAt = $now->endOfDay();

        $new = 0;

        foreach (array_unique(array_filter($objectIds, static fn (string $id): bool => $id !== '')) as $id) {
            $key = "usage:read-dedup:{$workspaceId}:{$platform}:{$day}:{$id}";

            // Cache::add is atomic: returns true only when the key did not exist,
            // i.e. this is the first time we've billed this id today.
            if (Cache::add($key, true, $expiresAt)) {
                $new++;
            }
        }

        return $new;
    }
}
