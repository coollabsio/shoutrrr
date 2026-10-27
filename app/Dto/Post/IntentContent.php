<?php

declare(strict_types=1);

namespace App\Dto\Post;

/**
 * The body of a publish_now / schedule_at intent: a single text or a thread,
 * plus optional media and mentions. `text` stays the legacy single-post input;
 * non-empty `segments` take precedence over it, as in create_post.
 */
final readonly class IntentContent
{
    /**
     * @param  list<string>  $segments
     * @param  list<string>  $mediaIds
     * @param  list<array{id: string, label: string, handles?: array<string, string|null>}>  $mentions
     */
    public function __construct(
        public string $text,
        public array $segments = [],
        public array $mediaIds = [],
        public array $mentions = [],
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            text: (string) ($validated['text'] ?? ''),
            segments: array_values($validated['segments'] ?? []),
            mediaIds: array_values($validated['media_ids'] ?? []),
            mentions: array_values($validated['mentions'] ?? []),
        );
    }

    /**
     * @return list<string>
     */
    public function effectiveSegments(): array
    {
        return $this->segments !== [] ? $this->segments : [$this->text];
    }

    /**
     * Fields folded into the idempotency fingerprint. Empty optional fields are
     * left out so a text-only request hashes exactly as it did before media,
     * segments, and mentions existed, keeping stored request hashes valid.
     *
     * @return array<string, mixed>
     */
    public function fingerprintFields(): array
    {
        return array_filter([
            'media_ids' => $this->mediaIds,
            'mentions' => $this->mentions,
            'segments' => $this->segments,
        ], static fn (array $value): bool => $value !== []);
    }
}
