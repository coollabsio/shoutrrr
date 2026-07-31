<?php

declare(strict_types=1);

namespace App\Dto\Publishing;

use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;

final readonly class PublishContext
{
    /**
     * @param  list<string>  $segments
     * @param  list<PostMedia>  $media
     * @param  array<string, mixed>  $credentials
     * @param  array<int, list<PostMedia>>  $mediaBySection
     */
    public function __construct(
        public PostTarget $target,
        public array $segments,
        public array $media,
        public ConnectedAccount $account,
        public array $credentials,
        public array $mediaBySection = [],
    ) {}

    /**
     * Legacy callers (or hand-built contexts, e.g. in tests) that never populate
     * mediaBySection get the same fallback SegmentMediaResolver applies for empty
     * placements: all media rides on section 0, none on later sections.
     *
     * @return list<PostMedia>
     */
    public function mediaForSection(int $index): array
    {
        if ($this->mediaBySection === []) {
            return $index === 0 ? $this->media : [];
        }

        return $this->mediaBySection[$index] ?? [];
    }
}
