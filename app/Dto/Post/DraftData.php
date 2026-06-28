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
     * @param  array<string, array{auto_split?: bool, content_override?: array{segments: list<string>, media_ids: list<string>}|null}>  $targetsByAccount
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
}
