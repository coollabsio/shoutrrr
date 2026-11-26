<?php

declare(strict_types=1);

namespace App\Dto\Post;

use App\Enums\TikTokPostMode;
use App\Enums\TikTokPrivacyLevel;

/**
 * The per-post choices TikTok's Direct Post UX requires the creator to make
 * themselves. Every default is the "nothing chosen" state the Content Sharing
 * Guidelines ask for: no privacy level, every interaction off, no commercial
 * disclosure. Inbox posts ignore all of it — the creator finishes those
 * settings in the TikTok app.
 */
final readonly class TikTokPostOptions
{
    public function __construct(
        public TikTokPostMode $mode = TikTokPostMode::Direct,
        public ?TikTokPrivacyLevel $privacyLevel = null,
        public bool $allowComment = false,
        public bool $allowDuet = false,
        public bool $allowStitch = false,
        public bool $discloseCommercial = false,
        public bool $brandOrganic = false,
        public bool $brandedContent = false,
    ) {}

    /**
     * Rebuild from the stored/request shape. Unknown enum values fall back to
     * the unset default rather than throwing, so a stored row can always be read.
     *
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        $data ??= [];
        $mode = $data['post_mode'] ?? null;
        $privacy = $data['privacy_level'] ?? null;

        return new self(
            mode: (is_string($mode) ? TikTokPostMode::tryFrom($mode) : null) ?? TikTokPostMode::Direct,
            privacyLevel: is_string($privacy) ? TikTokPrivacyLevel::tryFrom($privacy) : null,
            allowComment: (bool) ($data['allow_comment'] ?? false),
            allowDuet: (bool) ($data['allow_duet'] ?? false),
            allowStitch: (bool) ($data['allow_stitch'] ?? false),
            discloseCommercial: (bool) ($data['disclose_commercial'] ?? false),
            brandOrganic: (bool) ($data['brand_organic'] ?? false),
            brandedContent: (bool) ($data['branded_content'] ?? false),
        );
    }

    /**
     * @return array{post_mode: string, privacy_level: string|null, allow_comment: bool, allow_duet: bool, allow_stitch: bool, disclose_commercial: bool, brand_organic: bool, branded_content: bool}
     */
    public function toArray(): array
    {
        return [
            'post_mode' => $this->mode->value,
            'privacy_level' => $this->privacyLevel?->value,
            'allow_comment' => $this->allowComment,
            'allow_duet' => $this->allowDuet,
            'allow_stitch' => $this->allowStitch,
            'disclose_commercial' => $this->discloseCommercial,
            'brand_organic' => $this->brandOrganic,
            'branded_content' => $this->brandedContent,
        ];
    }

    /**
     * The choices a copy of the post starts with. Only the delivery mode
     * carries over. TikTok's Content Sharing Guidelines have the creator pick
     * who can view each post by hand, with no default, and turn on every
     * interaction and the commercial disclosure themselves, so a copy asks for
     * those again; the publish precheck holds a Direct Post until a privacy
     * level is chosen.
     */
    public function forCopy(): self
    {
        return new self(mode: $this->mode);
    }

    public function isDirectPost(): bool
    {
        return $this->mode === TikTokPostMode::Direct;
    }

    /**
     * The disclosure toggle is on but neither "Your brand" nor "Branded
     * content" is ticked — TikTok requires at least one before posting.
     */
    public function hasIncompleteDisclosure(): bool
    {
        return $this->discloseCommercial && ! $this->brandOrganic && ! $this->brandedContent;
    }
}
