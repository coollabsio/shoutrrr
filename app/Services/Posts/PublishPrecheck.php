<?php

declare(strict_types=1);

namespace App\Services\Posts;

use App\Enums\Platform;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostMediaPlacement;
use App\Models\PostTarget;
use App\Services\Publishing\SegmentMediaResolver;
use App\Support\GoogleBusinessProfileLocalPostOptions;
use Illuminate\Support\Collection;

class PublishPrecheck
{
    public function __construct(
        private readonly PostSplitter $splitter,
        private readonly SegmentMediaResolver $segmentMediaResolver,
    ) {}

    /**
     * Targets whose stored content would be rejected by the platform. Reuses the
     * same validation the composer preview shows, so a doomed publish is stopped
     * before dispatch instead of failing per-target on the platform API.
     *
     * A target with neither text nor media is blocked as `empty` — there is
     * nothing to post, and the platform limit checks are meaningless on it.
     *
     * The media-required rule lives here rather than in
     * PostSplitter::validateSections() because split() calls that method with a
     * hardcoded media count of 0 (it runs before media is known), which would
     * report a false `media_required` on every Instagram draft.
     *
     * @return list<array{connected_account_id: string, handle: ?string, platform: string, issues: list<string>}>
     */
    public function blockingTargets(Post $post): array
    {
        $media = $post->media;
        $mediaCount = $media->count();

        /** @var list<array{connected_account_id: string, handle: ?string, platform: string, issues: list<string>}> $blocking */
        $blocking = [];

        foreach ($post->targets as $target) {
            /** @var PostTarget $target */
            $issues = $target->platform === Platform::GoogleBusinessProfile || $this->hasContent($target, $mediaCount)
                ? $this->targetIssues($target, $media)
                : ['empty'];

            if ($issues === []) {
                continue;
            }

            $blocking[] = [
                'connected_account_id' => (string) $target->connected_account_id,
                'handle' => $target->account?->handle,
                'platform' => $target->platform->value,
                'issues' => $issues,
            ];
        }

        return $blocking;
    }

    /**
     * A human-readable reason a target was blocked, for the stored error_message
     * on the non-interactive dispatch paths (scheduler, MCP) where there is no
     * client to render the raw issue codes.
     *
     * @param  list<string>  $issues
     */
    public function describe(array $issues, Platform $platform): string
    {
        $label = $platform->label();

        $messages = array_map(static fn (string $issue): string => match ($issue) {
            'empty' => 'Add text or media before publishing.',
            'media_required' => "{$label} needs at least one image or video.",
            'section_too_long' => "A section is over {$label}'s length limit.",
            'too_many_sections' => "Too many thread sections for {$label}.",
            'too_many_media' => "Too many media items for {$label}.",
            'mixed_video_and_images' => 'A post can contain one video or images, not both.',
            'video_too_long' => "The video is longer than {$label} allows.",
            'video_too_large' => "The video is larger than {$label} allows.",
            'gif_not_mixable' => "{$label} allows only one GIF and won't mix it with other media.",
            'unplaced_media' => "Some attached media isn't placed in this post — remove it or add it to a thread section.",
            'gbp_summary_required' => 'Google Business Profile requires a post summary.',
            'gbp_threads_not_supported' => 'Google Business Profile does not support threaded posts.',
            'gbp_event_title_required' => 'Google Business Profile events require a title.',
            'gbp_event_schedule_required' => 'Google Business Profile events require a valid start and end time.',
            'gbp_offer_title_required' => 'Google Business Profile offers require a title.',
            'gbp_offer_schedule_required' => 'Google Business Profile offers require a valid start and end time.',
            'gbp_offer_redemption_url_required' => 'Google Business Profile offers require a valid redemption URL.',
            'gbp_cta_url_invalid' => 'Google Business Profile CTA URLs must be safe HTTPS URLs.',
            'gbp_unsafe_url' => 'Google Business Profile post text contains an unsafe URL.',
            'gbp_phone_stuffing' => 'Google Business Profile post text contains too many phone numbers.',
            'gbp_regulated_promotion' => 'Google Business Profile promotional content cannot advertise regulated products or services.',
            'gbp_hotel_promotion' => 'Google Business Profile hotels cannot publish offers, deals, or promotions.',
            'gbp_repetitive_content' => 'Google Business Profile post text appears repetitive or spam-like.',
            default => "{$label} can't publish this post yet.",
        }, $issues);

        return implode(' ', array_values(array_unique($messages)));
    }

    /**
     * Platform-limit issues for a target that has content, plus the media rules
     * the section-length limits don't cover.
     *
     * `too_many_media` is deliberately not sourced from
     * PostSplitter::validateSections() here: that method's media-count argument
     * is checked against the whole post, but publishing caps media per thread
     * section (each connector slices `mediaForSection()` to
     * `Platform::maxMedia()`). A thread with 4 images on each of two sections is
     * publishable even though it holds 8 images overall, so `mediaIssues()`
     * recomputes this per section using the same grouping `mixIssues()` already
     * relies on.
     *
     * @param  Collection<int, PostMedia>  $media
     * @return list<string>
     */
    private function targetIssues(PostTarget $target, Collection $media): array
    {
        $platform = $target->platform;

        $issues = $this->splitter->validateSections(
            $target->sections,
            $platform,
            0,
            $target->account?->maxTextLength(),
        );

        if ($media->count() === 0 && $platform->requiresMedia()) {
            $issues[] = 'media_required';
        }

        foreach ($this->mediaIssues($target, $platform, $media) as $issue) {
            $issues[] = $issue;
        }

        if ($platform === Platform::GoogleBusinessProfile) {
            $issues = [...$issues, ...$this->googleBusinessProfileIssues($target, $media)];
        }

        return array_values(array_unique($issues));
    }

    /** @return list<string> */
    /**
     * @param  Collection<int, PostMedia>  $media
     * @return list<string>
     */
    private function googleBusinessProfileIssues(PostTarget $target, Collection $media): array
    {
        $issues = [];

        if (trim(implode('', $target->sections)) === '') {
            $issues[] = 'gbp_summary_required';
        }
        if (count($target->sections) > 1) {
            $issues[] = 'gbp_threads_not_supported';
        }
        $summary = implode("\n", $target->sections);
        if ($this->containsUnsafeUrl($summary)) {
            $issues[] = 'gbp_unsafe_url';
        }
        if ($this->phoneNumberCount($summary) > 1) {
            $issues[] = 'gbp_phone_stuffing';
        }
        if ($this->containsRegulatedPromotion($summary)) {
            $issues[] = 'gbp_regulated_promotion';
        }
        if ($this->containsRepetitiveContent($summary)) {
            $issues[] = 'gbp_repetitive_content';
        }

        $options = GoogleBusinessProfileLocalPostOptions::normalize($target->provider_options['google_business_profile'] ?? []);

        $type = $options['local_post_type'] ?? 'standard';
        $ctaType = GoogleBusinessProfileLocalPostOptions::ctaType($options);
        $ctaUrl = GoogleBusinessProfileLocalPostOptions::ctaUrl($options);
        if (($ctaType !== null && $ctaType !== 'CALL' && $ctaUrl === null)
            || ($ctaUrl !== null && $this->isInvalidUrl($ctaUrl))) {
            $issues[] = 'gbp_cta_url_invalid';
        }
        if ($this->containsHotelPromotion($summary, $type)) {
            $issues[] = 'gbp_hotel_promotion';
        }
        if (! in_array($type, ['event', 'offer'], true)) {
            return $issues;
        }

        $prefix = 'gbp_'.$type;
        if (! filled($options['title'] ?? null)) {
            $issues[] = $prefix.'_title_required';
        }
        if (! $this->hasValidSchedule($options)) {
            $issues[] = $prefix.'_schedule_required';
        }
        if ($type === 'offer' && $this->isInvalidUrl($options['redemption_url'] ?? null)) {
            $issues[] = 'gbp_offer_redemption_url_required';
        }

        return $issues;
    }

    /** @param array<string, mixed> $options */
    private function hasValidSchedule(array $options): bool
    {
        $start = strtotime((string) ($options['start_at'] ?? ''));
        $end = strtotime((string) ($options['end_at'] ?? ''));

        return $start !== false && $end !== false && $end > $start;
    }

    private function isInvalidUrl(mixed $url): bool
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return true;
        }

        $parts = parse_url($url);
        $host = $parts['host'] ?? null;

        return ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($host)
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || in_array(strtolower($host), ['bit.ly', 'tinyurl.com', 't.co'], true);
    }

    private function containsUnsafeUrl(string $text): bool
    {
        preg_match_all('/https?:\/\/[^\s<]+/i', $text, $matches);

        foreach ($matches[0] as $url) {
            if ($this->isInvalidUrl($url)) {
                return true;
            }
        }

        return false;
    }

    private function phoneNumberCount(string $text): int
    {
        return preg_match_all('/(?:\+?\d[\d().\-\s]{6,}\d)/', $text) ?: 0;
    }

    private function containsRegulatedPromotion(string $text): bool
    {
        return preg_match('/\b(discount|deal|offer|promotion|sale|coupon)\b/i', $text) === 1
            && preg_match('/\b(alcohol|cannabis|marijuana|tobacco|vape|gambling|casino|firearm|weapon|adult)\b/i', $text) === 1;
    }

    private function containsHotelPromotion(string $text, mixed $type): bool
    {
        return preg_match('/\b(hotel|motel|resort|inn)\b/i', $text) === 1
            && ($type === 'offer' || preg_match('/\b(discount|deal|offer|promotion|sale|coupon)\b/i', $text) === 1);
    }

    private function containsRepetitiveContent(string $text): bool
    {
        preg_match_all('/[^.!?]+[.!?]?/', strtolower($text), $matches);
        $sentences = array_filter(array_map('trim', $matches[0]), static fn (string $sentence): bool => $sentence !== '');

        return count($sentences) !== count(array_unique($sentences));
    }

    /**
     * Media-attribute rules the connectors enforce only at publish time — the
     * per-section media cap, image/video mixing, GIF mixing, and unplaced media.
     * Video caps are checked against the whole target's media (never
     * re-encoded server-side, so caps can't self-heal the way images can). The
     * other rules are checked per thread segment: each connector publishes a
     * thread segment as its own post (see SegmentMediaResolver/mediaForSection),
     * so e.g. two segments each holding 4 images don't violate a platform's
     * 4-image cap that only applies within a single published post.
     *
     * @param  Collection<int, PostMedia>  $media
     * @return list<string>
     */
    private function mediaIssues(PostTarget $target, Platform $platform, Collection $media): array
    {
        if ($media->isEmpty()) {
            return [];
        }

        $issues = [];
        $sections = $this->mediaBySection($target, $media);

        foreach ($sections as $sectionMedia) {
            if ($sectionMedia->count() > $platform->maxMedia()) {
                $issues[] = 'too_many_media';
            }

            foreach ($this->mixIssues($platform, $sectionMedia) as $issue) {
                $issues[] = $issue;
            }
        }

        if ($target->placements->isNotEmpty()) {
            $placedIds = collect($sections)
                ->flatMap(fn (Collection $sectionMedia): Collection => $sectionMedia)
                ->map(fn (PostMedia $item): string => (string) $item->id)
                ->all();
            $attachedIds = $media->map(fn (PostMedia $item): string => (string) $item->id)->all();

            if (array_diff($attachedIds, $placedIds) !== []) {
                $issues[] = 'unplaced_media';
            }
        }

        $videos = $media->filter(fn (PostMedia $item): bool => $item->isVideo());
        foreach ($videos as $video) {
            if ($video->duration_seconds !== null && $video->duration_seconds > $platform->maxVideoDurationSeconds()) {
                $issues[] = 'video_too_long';
            }

            if ($video->size_bytes > $platform->maxVideoBytes()) {
                $issues[] = 'video_too_large';
            }
        }

        return $issues;
    }

    /**
     * The image/video and GIF mixing rules, judged against a single thread
     * segment's media rather than the whole post's.
     *
     * @param  Collection<int, PostMedia>  $media
     * @return list<string>
     */
    private function mixIssues(Platform $platform, Collection $media): array
    {
        $issues = [];

        $videos = $media->filter(fn (PostMedia $item): bool => $item->isVideo());
        $images = $media->reject(fn (PostMedia $item): bool => $item->isVideo());

        // On platforms that don't build a real mixed carousel, the connector keeps
        // only the first video and silently drops every image — a "successful"
        // publish would be missing content.
        if ($videos->isNotEmpty() && $images->isNotEmpty() && ! $platform->combinesVideoAndImages()) {
            $issues[] = 'mixed_video_and_images';
        }

        if (! $platform->allowsGifWithOtherMedia()) {
            $gifCount = $media->filter(fn (PostMedia $item): bool => $item->mime === 'image/gif')->count();
            if ($gifCount >= 1 && ($media->count() > 1 || $gifCount > 1)) {
                $issues[] = 'gif_not_mixable';
            }
        }

        return $issues;
    }

    /**
     * This target's media grouped by resolved thread segment, mirroring how
     * PublishPostTarget builds the PublishContext each connector actually
     * publishes from. Targets with no placements (e.g. media added before
     * per-segment placements existed, or fixtures that don't set them up) fall
     * back to a single segment holding all of the target's media.
     *
     * @param  Collection<int, PostMedia>  $media
     * @return array<int, Collection<int, PostMedia>>
     */
    private function mediaBySection(PostTarget $target, Collection $media): array
    {
        $placements = array_values($target->placements
            ->map(fn (PostMediaPlacement $placement): array => [
                'post_media_id' => $placement->post_media_id,
                'segment_ref' => $placement->segment_ref,
                'position' => $placement->position,
            ])
            ->all());

        $bySection = $this->segmentMediaResolver->resolve(
            sections: $target->sections,
            sectionSources: $target->section_sources ?? [],
            segmentBreaks: $target->segment_breaks ?? [],
            placements: $placements,
            allMedia: array_values($media->all()),
        );

        return array_map(static fn (array $sectionMedia): Collection => collect($sectionMedia), $bySection);
    }

    /**
     * Whether a target has anything worth posting. Empty segments are stored as
     * a single blank section by PostSplitter, so a text-less target arrives here
     * as `['']` rather than `[]`.
     */
    private function hasContent(PostTarget $target, int $mediaCount): bool
    {
        if ($mediaCount > 0) {
            return true;
        }

        foreach ($target->sections as $section) {
            if (trim($section) !== '') {
                return true;
            }
        }

        return false;
    }
}
