<?php

declare(strict_types=1);

namespace App\Services\Posts;

use App\Enums\Platform;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostMediaPlacement;
use App\Models\PostTarget;
use App\Services\Publishing\SegmentMediaResolver;
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
            $issues = $this->hasContent($target, $mediaCount)
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
            'video_bad_aspect_ratio' => "The video's aspect ratio is outside {$label}'s allowed range (widest 3:1, tallest 1:3).",
            'gif_not_mixable' => "{$label} allows only one GIF and won't mix it with other media.",
            'unplaced_media' => "Some attached media isn't placed in this post — remove it or add it to a thread section.",
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

        return array_values(array_unique($issues));
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

        foreach ($media->filter(fn (PostMedia $item): bool => $item->isVideo()) as $video) {
            foreach ($this->videoIssues($platform, $video) as $issue) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    /**
     * Per-video limit rules — duration, byte size, and aspect ratio — for a
     * single attached video. Aspect ratio is only bounded on some platforms (X
     * rejects anything outside 1:3–3:1); incomplete client metadata (a missing
     * width or height) is left to publish time rather than blocked here.
     *
     * @return list<string>
     */
    private function videoIssues(Platform $platform, PostMedia $video): array
    {
        $issues = [];

        if ($video->duration_seconds !== null && $video->duration_seconds > $platform->maxVideoDurationSeconds()) {
            $issues[] = 'video_too_long';
        }

        if ($video->size_bytes > $platform->maxVideoBytes()) {
            $issues[] = 'video_too_large';
        }

        $range = $platform->videoAspectRatioRange();
        if ($range !== null && $video->width > 0 && $video->height > 0) {
            $ratio = $video->width / $video->height;
            if ($ratio < $range['min'] || $ratio > $range['max']) {
                $issues[] = 'video_bad_aspect_ratio';
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
