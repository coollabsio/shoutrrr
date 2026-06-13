<?php

declare(strict_types=1);

namespace App\Services\Publishing\Connectors;

use App\Dto\Publishing\PublishContext;
use App\Dto\Publishing\PublishResult;
use App\Enums\ErrorKind;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\Concerns\MapsHttpErrors;
use App\Services\Publishing\Contracts\PublishConnector;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Storage;

class XConnector implements PublishConnector
{
    use MapsHttpErrors;

    private const string TWEETS_URL = 'https://api.twitter.com/2/tweets';

    // v2 media upload (the v1.1 upload.twitter.com endpoint was deprecated 2025-03-31).
    // Simple single-request upload is sufficient for images; chunking is only required
    // for video/large media. Requires the OAuth2 `media.write` scope (see Platform::X).
    private const string MEDIA_URL = 'https://api.x.com/2/media/upload';

    public function __construct(private readonly HttpFactory $http) {}

    public function publish(PublishContext $context): PublishResult
    {
        $token = (string) ($context->credentials['access_token'] ?? '');
        $remoteIds = $context->target->remote_ids ?? [];

        try {
            $mediaIds = $this->uploadMedia($context->media, $token);

            foreach ($context->segments as $index => $text) {
                // Resume: skip segments already posted on a prior attempt.
                if (isset($remoteIds[$index])) {
                    continue;
                }

                $body = ['text' => $text];

                if ($index === 0 && $mediaIds !== []) {
                    $body['media'] = ['media_ids' => $mediaIds];
                }

                $previous = $remoteIds[$index - 1] ?? null;

                if ($previous !== null) {
                    $body['reply'] = ['in_reply_to_tweet_id' => $previous];
                }

                $response = $this->http
                    ->withToken($token)
                    ->acceptJson()
                    ->post(self::TWEETS_URL, $body);

                if ($response->failed()) {
                    return $this->mapFailure($response);
                }

                $remoteIds[$index] = (string) $response->json('data.id');

                // Persist this segment's id BEFORE sending the next one so a mid-thread
                // death resumes (rather than re-posts) the already-published segments (spec §4.3).
                $context->target->forceFill([
                    'remote_id' => $remoteIds[0],
                    'remote_ids' => array_values($remoteIds),
                ])->save();
            }
        } catch (XRequestFailed $e) {
            return $this->mapFailure($e->response);
        } catch (ConnectionException $e) {
            return PublishResult::failure(ErrorKind::Network, $e->getMessage());
        }

        return PublishResult::success(array_values($remoteIds));
    }

    public function delete(PostTarget $target, array $credentials): void
    {
        $token = (string) ($credentials['access_token'] ?? '');

        foreach ($target->remote_ids ?? array_filter([$target->remote_id]) as $id) {
            $this->http->withToken($token)->delete(self::TWEETS_URL.'/'.$id);
        }
    }

    /**
     * @param  list<PostMedia>  $media
     * @return list<string>
     */
    private function uploadMedia(array $media, string $token): array
    {
        $ids = [];

        foreach ($media as $item) {
            $bytes = Storage::disk($item->disk)->get($item->path);
            $response = $this->http
                ->withToken($token)
                ->asMultipart()
                ->attach('media', (string) $bytes, 'upload')
                ->post(self::MEDIA_URL, ['media_category' => 'tweet_image']);

            if ($response->failed()) {
                throw new XRequestFailed($response);
            }

            // v2 returns the numeric media id under data.id (v1.1 used media_id_string).
            $ids[] = (string) $response->json('data.id');
        }

        return $ids;
    }

    private function mapFailure(Response $response): PublishResult
    {
        $kind = $this->isDuplicateContent($response)
            ? ErrorKind::DuplicateContent
            : $this->classifyStatus($response->status());

        $message = (string) ($response->json('title') ?? $response->json('detail') ?? 'X request failed');

        return PublishResult::failure($kind, $message, $response->status(), $this->excerpt($response), $this->retryAfter($response));
    }

    /**
     * X returns HTTP 403 for duplicate posts. Detect them via the response body so the
     * job treats them as a terminal DuplicateContent failure rather than a retryable one.
     */
    private function isDuplicateContent(Response $response): bool
    {
        if ($response->status() !== 403) {
            return false;
        }

        $haystacks = array_filter([
            (string) $response->json('detail'),
            (string) $response->json('title'),
        ]);

        /** @var list<array<string, mixed>> $errors */
        $errors = (array) ($response->json('errors') ?? []);

        foreach ($errors as $error) {
            if (isset($error['message'])) {
                $haystacks[] = (string) $error['message'];
            }

            if ((int) ($error['code'] ?? 0) === 187) {
                return true;
            }
        }

        if ((int) ($response->json('code') ?? 0) === 187) {
            return true;
        }

        foreach ($haystacks as $haystack) {
            if (mb_stripos($haystack, 'duplicate') !== false) {
                return true;
            }
        }

        return false;
    }
}

/**
 * Internal signal so a failed media upload short-circuits to the shared HTTP-error
 * mapping without pushing an empty media id. Not part of the public connector surface.
 *
 * @internal
 */
final class XRequestFailed extends \RuntimeException
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct('X request failed.');
    }
}
