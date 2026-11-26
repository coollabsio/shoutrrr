<?php

declare(strict_types=1);

namespace App\Dto\ConnectedAccount;

use App\Enums\TikTokPrivacyLevel;

/**
 * What a TikTok creator may do right now, from the creator info endpoint.
 * TikTok requires clients to read this fresh before every Direct Post: the
 * privacy levels offered depend on whether the account is public or private,
 * and creators can switch comments, duets and stitches off account-wide.
 *
 * @see https://developers.tiktok.com/doc/content-posting-api-reference-query-creator-info
 */
final readonly class TikTokCreatorInfo
{
    /**
     * @param  list<TikTokPrivacyLevel>  $privacyLevels
     */
    public function __construct(
        public string $nickname,
        public string $username,
        public array $privacyLevels,
        public bool $commentDisabled,
        public bool $duetDisabled,
        public bool $stitchDisabled,
        public int $maxVideoDurationSeconds,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromResponse(array $data): self
    {
        $levels = [];

        foreach ((array) ($data['privacy_level_options'] ?? []) as $option) {
            $level = is_string($option) ? TikTokPrivacyLevel::tryFrom($option) : null;

            if ($level instanceof TikTokPrivacyLevel) {
                $levels[] = $level;
            }
        }

        return new self(
            nickname: (string) ($data['creator_nickname'] ?? ''),
            username: (string) ($data['creator_username'] ?? ''),
            privacyLevels: $levels,
            commentDisabled: (bool) ($data['comment_disabled'] ?? false),
            duetDisabled: (bool) ($data['duet_disabled'] ?? false),
            stitchDisabled: (bool) ($data['stitch_disabled'] ?? false),
            maxVideoDurationSeconds: (int) ($data['max_video_post_duration_sec'] ?? 0),
        );
    }

    public function allowsPrivacy(TikTokPrivacyLevel $level): bool
    {
        return in_array($level, $this->privacyLevels, true);
    }

    /**
     * @return array{nickname: string, username: string, privacy_levels: list<array{value: string, label: string}>, comment_disabled: bool, duet_disabled: bool, stitch_disabled: bool, max_video_duration_seconds: int}
     */
    public function toArray(): array
    {
        return [
            'nickname' => $this->nickname,
            'username' => $this->username,
            'privacy_levels' => array_map(static fn (TikTokPrivacyLevel $level): array => [
                'value' => $level->value,
                'label' => $level->label(),
            ], $this->privacyLevels),
            'comment_disabled' => $this->commentDisabled,
            'duet_disabled' => $this->duetDisabled,
            'stitch_disabled' => $this->stitchDisabled,
            'max_video_duration_seconds' => $this->maxVideoDurationSeconds,
        ];
    }
}
