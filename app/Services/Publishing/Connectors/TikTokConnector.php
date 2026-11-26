<?php

declare(strict_types=1);

namespace App\Services\Publishing\Connectors;

use App\Dto\ConnectedAccount\TikTokCreatorInfo;
use App\Dto\Post\TikTokPostOptions;
use App\Dto\Publishing\MediaUploadState;
use App\Dto\Publishing\PublishContext;
use App\Dto\Publishing\PublishResult;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\TikTokPrivacyLevel;
use App\Enums\UsageCategory;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\ConnectedAccounts\TikTok\TikTokApiError;
use App\Services\ConnectedAccounts\TikTok\TikTokApiException;
use App\Services\ConnectedAccounts\TikTok\TikTokCreatorInfoClient;
use App\Services\Media\ImageConversionFailed;
use App\Services\Media\PublicMediaUrl;
use App\Services\Publishing\Contracts\PublishConnector;
use App\Services\Usage\Concerns\TracksUsage;
use App\Support\ConnectionFailure;
use App\Support\UsageOperation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Publishes to TikTok through the Content Posting API: one video, or a photo
 * post of up to 35 images, either straight to the creator's profile (Direct
 * Post) or into their TikTok inbox as a draft they finish in the app.
 *
 * Media is handed over with PULL_FROM_URL — TikTok fetches it from this
 * instance's public media URL, as its guidelines ask when the content already
 * sits on the client's servers. The URL's domain or prefix must be verified in
 * the TikTok developer portal.
 *
 * Publishing is asynchronous. The first run initializes the post and stores
 * TikTok's `publish_id`; later runs poll its status through the job's
 * MediaProcessing re-dispatch until TikTok reports it posted, delivered to
 * the inbox, or failed. The id is only forgotten when TikTok says the post
 * failed or that the id does not exist: any other answer keeps it, so a retry
 * polls the same post instead of uploading it twice. Before a Direct Post is
 * initialized, the creator's current options are read again and the post
 * fails — never silently changes — if a stored choice is no longer allowed.
 *
 * @see https://developers.tiktok.com/doc/content-posting-api-reference-direct-post
 * @see https://developers.tiktok.com/doc/content-posting-api-reference-upload-video
 * @see https://developers.tiktok.com/doc/content-posting-api-reference-photo-post
 * @see https://developers.tiktok.com/doc/content-posting-api-reference-get-video-status
 * @see https://developers.tiktok.com/doc/content-sharing-guidelines
 */
class TikTokConnector implements PublishConnector
{
    use TracksUsage;

    private const string BASE_URL = 'https://open.tiktokapis.com/v2/post/publish';

    /** MediaUploadState key holding TikTok's publish_id for this target. */
    private const string PUBLISH_KEY = 'tiktok-publish';

    /** Statuses TikTok documents for a post it is still working on. */
    private const array IN_PROGRESS_STATUSES = ['PROCESSING_UPLOAD', 'PROCESSING_DOWNLOAD'];

    /** `fail_reason` values TikTok documents as worth retrying from the start. */
    private const array RETRYABLE_FAIL_REASONS = ['internal', 'video_pull_failed', 'photo_pull_failed'];

    private const array FAIL_REASON_MESSAGES = [
        'internal' => 'TikTok had an internal error while processing the post.',
        'video_pull_failed' => 'TikTok could not download the video. Check that media URLs on this instance are public HTTPS and verified in the TikTok developer portal.',
        'photo_pull_failed' => 'TikTok could not download the photos. Check that media URLs on this instance are public HTTPS and verified in the TikTok developer portal.',
        'auth_removed' => "The creator removed this app's access on TikTok. Reconnect the TikTok account.",
        'publish_cancelled' => 'The post was cancelled on TikTok.',
        'file_format_check_failed' => 'TikTok does not accept this file format.',
        'duration_check_failed' => 'TikTok does not accept the length of this video.',
        'frame_rate_check_failed' => 'TikTok only accepts videos between 23 and 60 frames per second.',
        'picture_size_check_failed' => 'TikTok does not accept the size of one of these photos.',
        'spam_risk_too_many_posts' => 'This TikTok account has reached its daily posting limit. Try again later.',
        'spam_risk_user_banned_from_posting' => 'TikTok is not letting this account post right now. Try again later.',
        'spam_risk_text' => 'TikTok flagged the caption as spam.',
        'spam_risk' => 'TikTok flagged this post as spam.',
    ];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly PublicMediaUrl $publicMediaUrl,
        private readonly TikTokCreatorInfoClient $creatorInfo,
    ) {}

    public function publish(PublishContext $context): PublishResult
    {
        $token = (string) ($context->credentials['access_token'] ?? '');

        if ($token === '') {
            return PublishResult::failure(ErrorKind::AuthExpired, 'TikTok access token unavailable; reconnect the account.');
        }

        if ($context->target->remote_id !== null) {
            return PublishResult::success($context->target->remote_ids ?? [$context->target->remote_id]);
        }

        $videos = array_values(array_filter($context->media, static fn (PostMedia $item): bool => $item->isVideo()));
        $images = array_values(array_filter($context->media, static fn (PostMedia $item): bool => ! $item->isVideo()));

        if ($context->media === []) {
            return PublishResult::failure(ErrorKind::Validation, 'TikTok needs a video or at least one photo.');
        }

        if (count($videos) > 1 || ($videos !== [] && $images !== []) || count($images) > Platform::TikTok->maxMedia()) {
            return PublishResult::failure(ErrorKind::Validation, 'A TikTok post is one video or up to 35 photos.');
        }

        $options = $context->target->tiktok_options ?? new TikTokPostOptions;
        $state = new MediaUploadState($context->target->media_upload_state);
        $publishId = $state->remoteRef(self::PUBLISH_KEY);

        try {
            if ($publishId !== null) {
                return $this->checkStatus($context, $state, $publishId);
            }

            $video = $videos[0] ?? null;
            $problem = $options->isDirectPost()
                ? $this->directPostProblem($options, $this->creatorInfo->query($context->account, $token), $video)
                : null;

            if ($problem !== null) {
                return PublishResult::failure(ErrorKind::Validation, $problem);
            }

            $publishId = $video instanceof PostMedia
                ? $this->initVideo($context, $options, $video, $token)
                : $this->initPhotos($context, $options, $images, $token);
        } catch (TikTokApiException $e) {
            return PublishResult::failure($e->error->kind(), $e->getMessage(), $e->error->httpStatus);
        } catch (ImageConversionFailed $e) {
            // The image can't be re-encoded into something TikTok accepts; retrying
            // won't change that, so fail with the reason rather than looping.
            return PublishResult::failure(ErrorKind::Unsupported, $e->getMessage());
        } catch (ConnectionException $e) {
            return PublishResult::failure(ErrorKind::Network, ConnectionFailure::report($e));
        }

        // Persist the id before anything else: from here on TikTok owns the post,
        // and a retry must poll it rather than create a second one.
        $state->markUploaded(self::PUBLISH_KEY, $publishId);
        $this->persistState($context, $state);

        return PublishResult::failure(ErrorKind::MediaProcessing, 'TikTok is processing the post.', retryAfter: $this->pollDelay($state));
    }

    /**
     * TikTok documents no endpoint for deleting a post made through the Content
     * Posting API, so deleting a TikTok post never dispatches a remote delete
     * (see Platform::supportsRemoteDelete()).
     */
    public function delete(PostTarget $target, array $credentials): void
    {
        throw new RuntimeException('TikTok has no API for deleting posts; delete it in the TikTok app.');
    }

    /**
     * Why the stored choices can't be published as a Direct Post for this creator
     * right now, or null when they can. Checked against fresh creator info.
     */
    private function directPostProblem(TikTokPostOptions $options, TikTokCreatorInfo $creator, ?PostMedia $video): ?string
    {
        if (! $options->privacyLevel instanceof TikTokPrivacyLevel) {
            return 'Choose who can view this TikTok post.';
        }

        if (! $creator->allowsPrivacy($options->privacyLevel)) {
            return "\"{$options->privacyLevel->label()}\" is no longer offered for this TikTok account. Choose who can view the post again.";
        }

        if ($options->allowComment && $creator->commentDisabled) {
            return 'This TikTok account has turned comments off. Untick "Allow comments" and try again.';
        }

        if (! $video instanceof PostMedia) {
            return null;
        }

        if ($options->allowDuet && $creator->duetDisabled) {
            return 'This TikTok account has turned duets off. Untick "Allow Duet" and try again.';
        }

        if ($options->allowStitch && $creator->stitchDisabled) {
            return 'This TikTok account has turned stitches off. Untick "Allow Stitch" and try again.';
        }

        if ($video->duration_seconds !== null
            && $creator->maxVideoDurationSeconds > 0
            && $video->duration_seconds > $creator->maxVideoDurationSeconds) {
            return "The video is longer than this TikTok account's {$creator->maxVideoDurationSeconds}-second limit.";
        }

        return null;
    }

    /**
     * A Direct Post video carries the caption and every choice; an inbox video
     * carries only the media — TikTok's inbox endpoint takes no caption, so the
     * creator adds it in the app.
     */
    private function initVideo(PublishContext $context, TikTokPostOptions $options, PostMedia $video, string $token): string
    {
        $sourceInfo = [
            'source' => 'PULL_FROM_URL',
            'video_url' => $this->publicMediaUrl->for($video, Platform::TikTok),
        ];

        if (! $options->isDirectPost()) {
            return $this->init($context, $token, '/inbox/video/init/', ['source_info' => $sourceInfo]);
        }

        $postInfo = [
            ...$this->captionField('title', $context),
            'privacy_level' => $options->privacyLevel?->value,
            'disable_comment' => ! $options->allowComment,
            'disable_duet' => ! $options->allowDuet,
            'disable_stitch' => ! $options->allowStitch,
            ...$this->disclosure($options),
        ];

        return $this->init($context, $token, '/video/init/', ['post_info' => $postInfo, 'source_info' => $sourceInfo]);
    }

    /**
     * @param  list<PostMedia>  $images
     */
    private function initPhotos(PublishContext $context, TikTokPostOptions $options, array $images, string $token): string
    {
        $postInfo = $this->captionField('description', $context);

        if ($options->isDirectPost()) {
            $postInfo = [
                ...$postInfo,
                'privacy_level' => $options->privacyLevel?->value,
                'disable_comment' => ! $options->allowComment,
                ...$this->disclosure($options),
            ];
        }

        return $this->init($context, $token, '/content/init/', [
            'media_type' => 'PHOTO',
            'post_mode' => $options->mode->photoPostMode(),
            'post_info' => $postInfo,
            'source_info' => [
                'source' => 'PULL_FROM_URL',
                'photo_images' => array_map(fn (PostMedia $image): string => $this->publicMediaUrl->for($image, Platform::TikTok), $images),
                'photo_cover_index' => 0,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     *
     * @throws TikTokApiException
     */
    private function init(PublishContext $context, string $token, string $path, array $body): string
    {
        $response = $this->request($token)->post(self::BASE_URL.$path, $body);

        $this->meter(UsageCategory::Publish, UsageOperation::POST, $context->account, $response);

        $error = TikTokApiError::from($response);

        if ($error instanceof TikTokApiError) {
            throw new TikTokApiException($error);
        }

        $publishId = $response->json('data.publish_id');

        if (! is_string($publishId) || $publishId === '') {
            throw new TikTokApiException(new TikTokApiError('missing_publish_id', $response->status(), 'TikTok did not return a publish id.'));
        }

        return $publishId;
    }

    /**
     * @throws TikTokApiException
     */
    private function checkStatus(PublishContext $context, MediaUploadState $state, string $publishId): PublishResult
    {
        $token = (string) $context->credentials['access_token'];
        $response = $this->request($token)->post(self::BASE_URL.'/status/fetch/', ['publish_id' => $publishId]);

        $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_STATUS_POLL, $context->account, $response);

        $error = TikTokApiError::from($response);

        if ($error instanceof TikTokApiError && $error->code === 'invalid_publish_id') {
            // TikTok says this id does not exist, so polling it can never succeed.
            // Whether anything was posted is unknown, so a new upload is left to
            // the user rather than to an automatic retry.
            $state->forget(self::PUBLISH_KEY);
            $state->resetPolls();
            $this->persistState($context, $state);

            return PublishResult::failure(
                ErrorKind::Validation,
                'TikTok no longer recognizes this upload, so whether it was posted is unknown. Check the TikTok profile and inbox before retrying: a retry uploads it again.',
                $error->httpStatus,
            );
        }

        if ($error instanceof TikTokApiError) {
            // A rate limit, an expired token or a TikTok outage says nothing about
            // the post itself: keep the publish id so the next attempt polls it.
            throw new TikTokApiException($error);
        }

        $status = (string) $response->json('data.status');

        if (in_array($status, ['PUBLISH_COMPLETE', 'SEND_TO_USER_INBOX'], true)) {
            // A public post comes back with its TikTok post id, which links to
            // it; a private post or an inbox draft only has the publish id.
            $postId = $response->json('data.publicaly_available_post_id.0');

            return PublishResult::success([is_int($postId) || is_string($postId) ? (string) $postId : $publishId]);
        }

        if ($status !== 'FAILED') {
            if (! in_array($status, self::IN_PROGRESS_STATUSES, true)) {
                // An undocumented status, or none, is not a failure: TikTok may
                // still post this. Keep polling the same id so nothing is posted
                // twice, and leave a trace so the status can be handled.
                Log::warning('TikTok returned an unknown publish status; still waiting for it.', [
                    'post_target_id' => $context->target->id,
                    'status' => $status,
                ]);
            }

            return PublishResult::failure(ErrorKind::MediaProcessing, 'TikTok is processing the post.', retryAfter: $this->pollDelay($state));
        }

        // FAILED: nothing was posted, so forget this publish_id and let a retry
        // start over.
        $state->forget(self::PUBLISH_KEY);
        $state->resetPolls();
        $this->persistState($context, $state);

        $reason = (string) $response->json('data.fail_reason');

        return PublishResult::failure(
            match (true) {
                in_array($reason, self::RETRYABLE_FAIL_REASONS, true) => ErrorKind::ServerError,
                $reason === 'auth_removed' => ErrorKind::AuthExpired,
                default => ErrorKind::Validation,
            },
            self::FAIL_REASON_MESSAGES[$reason] ?? 'TikTok could not publish the post ('.($reason !== '' ? $reason : strtolower($status)).').',
            $response->status(),
        );
    }

    /**
     * Seconds until the next status poll, longer the more polls were already
     * made. TikTok first downloads the media from this instance and then
     * processes it, which takes minutes for a large video. With the publish
     * job's 40-poll budget this waits about half an hour in all, and never
     * comes near TikTok's limit of 30 status requests a minute per token.
     */
    private function pollDelay(MediaUploadState $state): int
    {
        return match (true) {
            $state->polls() < 6 => 10,
            $state->polls() < 15 => 30,
            default => 60,
        };
    }

    /**
     * The caption, under the field name the endpoint uses, or nothing when the
     * post has no text.
     *
     * @return array<string, string>
     */
    private function captionField(string $field, PublishContext $context): array
    {
        $caption = implode("\n\n", array_values(array_filter(
            array_map(trim(...), $context->segments),
            static fn (string $segment): bool => $segment !== '',
        )));

        return $caption === '' ? [] : [$field => $caption];
    }

    /**
     * Commercial content disclosure. Both flags are always sent so an undisclosed
     * post is explicitly marked as such.
     *
     * @return array{brand_organic_toggle: bool, brand_content_toggle: bool}
     */
    private function disclosure(TikTokPostOptions $options): array
    {
        return [
            'brand_organic_toggle' => $options->discloseCommercial && $options->brandOrganic,
            'brand_content_toggle' => $options->discloseCommercial && $options->brandedContent,
        ];
    }

    private function request(string $token): PendingRequest
    {
        return $this->http->withToken($token)->acceptJson()->timeout(30)->connectTimeout(10)
            ->contentType('application/json; charset=UTF-8');
    }

    private function persistState(PublishContext $context, MediaUploadState $state): void
    {
        $context->target->forceFill(['media_upload_state' => $state->toArray()])->save();
    }
}
