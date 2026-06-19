<?php

declare(strict_types=1);

namespace App\Dto\Publishing;

/**
 * Typed accessor over a PostTarget's `media_upload_state` JSON: a per-media map of the
 * in-flight platform upload reference + transcode state, plus a reserved poll counter.
 *
 * Centralizes keys that were otherwise read as bare strings (`remote_ref`, `state`,
 * `blob`, `_polls`) across the publish job and all three connectors, so a typo can't
 * silently break resume/poll accounting.
 */
final class MediaUploadState
{
    private const string POLLS_KEY = '_polls';

    /** @var array<string, mixed> */
    private array $state;

    /**
     * @param  array<string, mixed>|null  $state
     */
    public function __construct(?array $state)
    {
        $this->state = $state ?? [];
    }

    /** The persisted platform reference (X media_id / LinkedIn video URN / Bluesky job id). */
    public function remoteRef(string $mediaId): ?string
    {
        $entry = $this->entry($mediaId);
        $ref = $entry['remote_ref'] ?? null;

        return is_string($ref) ? $ref : null;
    }

    /** Record that a media item has been uploaded to the platform and is now transcoding. */
    public function markUploaded(string $mediaId, string $remoteRef): void
    {
        $this->state[$mediaId] = ['remote_ref' => $remoteRef, 'state' => 'processing'];
    }

    /**
     * The completed transcode blob (Bluesky), or an empty array if not yet stored.
     *
     * @return array<string, mixed>
     */
    public function blob(string $mediaId): array
    {
        $blob = $this->entry($mediaId)['blob'] ?? [];

        return is_array($blob) ? $blob : [];
    }

    /**
     * @param  array<string, mixed>  $blob
     */
    public function setBlob(string $mediaId, array $blob): void
    {
        $entry = $this->entry($mediaId);
        $entry['blob'] = $blob;
        $this->state[$mediaId] = $entry;
    }

    public function polls(): int
    {
        return (int) ($this->state[self::POLLS_KEY] ?? 0);
    }

    /** Increment and return the transcode-poll count (kept separate from the publish-attempt budget). */
    public function incrementPolls(): int
    {
        $next = $this->polls() + 1;
        $this->state[self::POLLS_KEY] = $next;

        return $next;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->state;
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $mediaId): array
    {
        $entry = $this->state[$mediaId] ?? [];

        return is_array($entry) ? $entry : [];
    }
}
