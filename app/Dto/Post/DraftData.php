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
     * @param  list<string>  $destinationIds
     * @param  list<string>  $mediaIds
     * @param  array<string, array{auto_split?: bool, content_override?: array{text?: string|null, media_ids?: list<string>}|null}>  $targetsByAccount
     */
    public function __construct(
        public readonly string $baseText,
        public readonly string $destinationKind,
        public readonly ?string $destinationId,
        public readonly array $destinationIds,
        public readonly array $mediaIds,
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
                $entry['content_override'] = $target['content_override'];
            }
            $targetsByAccount[$target['connected_account_id']] = $entry;
        }

        return new self(
            baseText: (string) ($payload['base_text'] ?? ''),
            destinationKind: (string) $destination['kind'],
            destinationId: $destination['id'] ?? null,
            destinationIds: array_values($destination['ids'] ?? []),
            mediaIds: array_values($payload['media_ids'] ?? []),
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
     * @return array{text?: string|null, media_ids?: list<string>}|null
     */
    public function overrideFor(string $accountId): ?array
    {
        return $this->targetsByAccount[$accountId]['content_override'] ?? null;
    }
}
