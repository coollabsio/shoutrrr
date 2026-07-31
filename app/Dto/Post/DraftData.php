<?php

declare(strict_types=1);

namespace App\Dto\Post;

final class DraftData
{
    /**
     * Per-account inputs keyed by account id. A key is present in an entry only
     * when the client explicitly sent it — this lets the service distinguish
     * "set this override to null" from "leave the existing override untouched"
     * (the smart-merge that preserves edits across a destination switch).
     *
     * @param  list<string>  $segments
     * @param  list<string>  $destinationIds
     * @param  list<string>  $mediaIds
     * @param  list<array{id: string, label: string, handles: array<string, string>}>  $mentions
     * @param  array<string, array{auto_split?: bool, format?: string, content_override?: array{segments: list<string>, media_ids: list<string>}|null, placements?: list<array{media_id: string, segment_ref: string, position: int}>, segment_breaks?: list<string>}>  $targetsByAccount
     * @param  list<string>  $segmentBreaks
     * @param  list<array{media_id: string, segment_ref: string, position: int}>  $placements
     */
    public function __construct(
        /** @var list<string> */
        public readonly array $segments,
        public readonly string $destinationKind,
        public readonly ?string $destinationId,
        public readonly array $destinationIds,
        public readonly array $mediaIds,
        public readonly array $mentions,
        public readonly array $targetsByAccount,
        public readonly ?string $expectedUpdatedAt,
        public readonly ?bool $autoRepost = null,
        /**
         * Whether the payload carried an `auto_repost` key at all. Distinguishes
         * "set the override to null" from "leave the stored override untouched"
         * so partial updates (MCP / API edits that never mention boosting) don't
         * silently reset a user's per-post choice.
         */
        public readonly bool $autoRepostProvided = false,
        public readonly array $segmentBreaks = [],
        public readonly array $placements = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $destination = $payload['destination'] ?? ['kind' => 'all'];

        $targetsByAccount = [];
        foreach (($payload['targets'] ?? []) as $target) {
            $entry = [];
            if (array_key_exists('auto_split', $target)) {
                $entry['auto_split'] = (bool) $target['auto_split'];
            }
            if (array_key_exists('content_override', $target)) {
                $entry['content_override'] = self::readOverride($target['content_override']);
            }
            if (array_key_exists('format', $target) && $target['format'] !== null) {
                $entry['format'] = (string) $target['format'];
            }
            if (array_key_exists('placements', $target)) {
                $entry['placements'] = self::readPlacements($target['placements']);
            }
            if (array_key_exists('segment_breaks', $target) && is_array($target['segment_breaks'])) {
                $entry['segment_breaks'] = array_values(array_map(static fn (mixed $b): string => (string) $b, $target['segment_breaks']));
            }
            $targetsByAccount[$target['connected_account_id']] = $entry;
        }

        return new self(
            segments: self::readSegments($payload),
            destinationKind: (string) $destination['kind'],
            destinationId: $destination['id'] ?? null,
            destinationIds: array_values($destination['ids'] ?? []),
            mediaIds: array_values($payload['media_ids'] ?? []),
            mentions: array_values($payload['mentions'] ?? []),
            targetsByAccount: $targetsByAccount,
            expectedUpdatedAt: $payload['expected_updated_at'] ?? null,
            autoRepost: $payload['auto_repost'] ?? null,
            autoRepostProvided: array_key_exists('auto_repost', $payload),
            segmentBreaks: isset($payload['segment_breaks']) && is_array($payload['segment_breaks'])
                ? array_values(array_map(static fn (mixed $b): string => (string) $b, $payload['segment_breaks']))
                : [],
            placements: self::readPlacements($payload['placements'] ?? null),
        );
    }

    public function hasAutoSplitFor(string $accountId): bool
    {
        return array_key_exists('auto_split', $this->targetsByAccount[$accountId] ?? []);
    }

    public function autoSplitFor(string $accountId): bool
    {
        return $this->targetsByAccount[$accountId]['auto_split'] ?? true;
    }

    public function hasOverrideFor(string $accountId): bool
    {
        return array_key_exists('content_override', $this->targetsByAccount[$accountId] ?? []);
    }

    /**
     * @return array{segments: list<string>, media_ids: list<string>}|null
     */
    public function overrideFor(string $accountId): ?array
    {
        return $this->targetsByAccount[$accountId]['content_override'] ?? null;
    }

    public function hasFormatFor(string $accountId): bool
    {
        return array_key_exists('format', $this->targetsByAccount[$accountId] ?? []);
    }

    public function formatFor(string $accountId): string
    {
        return $this->targetsByAccount[$accountId]['format'] ?? 'feed';
    }

    /** @return list<array{media_id: string, segment_ref: string, position: int}> */
    public function placementsFor(string $accountId): array
    {
        return $this->targetsByAccount[$accountId]['placements'] ?? $this->placements;
    }

    /** @return list<string> */
    public function segmentBreaksFor(string $accountId): array
    {
        return $this->targetsByAccount[$accountId]['segment_breaks'] ?? $this->segmentBreaks;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private static function readSegments(array $payload): array
    {
        if (isset($payload['segments']) && is_array($payload['segments'])) {
            return array_values(array_map(static fn (mixed $s): string => (string) $s, $payload['segments']));
        }

        // Back-compat: a plain `base_text` string becomes one segment.
        return [(string) ($payload['base_text'] ?? '')];
    }

    /**
     * @return array{segments: list<string>, media_ids: list<string>}|null
     */
    private static function readOverride(mixed $override): ?array
    {
        if (! is_array($override)) {
            return null;
        }

        if ($override === []) {
            return null;
        }

        $segments = isset($override['segments']) && is_array($override['segments'])
            ? array_values(array_map(static fn (mixed $s): string => (string) $s, $override['segments']))
            : [(string) ($override['text'] ?? '')];

        return [
            'segments' => $segments,
            'media_ids' => array_values($override['media_ids'] ?? []),
        ];
    }

    /**
     * @return list<array{media_id: string, segment_ref: string, position: int}>
     */
    private static function readPlacements(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_map(static fn (array $p): array => [
            'media_id' => (string) $p['media_id'],
            'segment_ref' => (string) ($p['segment_ref'] ?? '__head__'),
            'position' => (int) ($p['position'] ?? 0),
        ], array_filter($raw, static fn (mixed $p): bool => is_array($p) && isset($p['media_id']))));
    }
}
