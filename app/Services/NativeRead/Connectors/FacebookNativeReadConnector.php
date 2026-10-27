<?php

declare(strict_types=1);

namespace App\Services\NativeRead\Connectors;

use App\Dto\NativeRead\NativeMedia;
use App\Dto\NativeRead\NativePost;
use App\Dto\NativeRead\NativeReadCursor;
use App\Dto\NativeRead\RecentPostsResult;
use App\Models\ConnectedAccount;
use App\Services\NativeRead\Contracts\NativeReadConnector;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;

class FacebookNativeReadConnector implements NativeReadConnector
{
    public function __construct(private readonly HttpFactory $http) {}

    public function fetchRecent(ConnectedAccount $account, NativeReadCursor $cursor, array $credentials): RecentPostsResult
    {
        try {
            $response = $this->http->timeout(10)->connectTimeout(5)->acceptJson()
                ->get($this->baseUrl().'/'.$account->remote_account_id.'/feed', [
                    'fields' => 'id,message,created_time,from,'
                        .'attachments{media_type,media{image{src},source},target{id},'
                        .'subattachments{media_type,media{image{src},source},target{id}}}',
                    'since' => $cursor->watermark->timestamp,
                    'limit' => 50,
                    'access_token' => (string) ($credentials['access_token'] ?? ''),
                ]);
        } catch (ConnectionException $e) {
            return RecentPostsResult::failed($e->getMessage());
        }

        if ($response->failed()) {
            return $response->status() === 429
                ? RecentPostsResult::rateLimited($response->body())
                : RecentPostsResult::failed($response->body());
        }

        $rows = [];
        $photoIds = [];
        foreach ((array) $response->json('data', []) as $row) {
            $id = (string) ($row['id'] ?? '');
            $createdAt = Carbon::parse((string) ($row['created_time'] ?? 'now'))->toImmutable();
            if ($id === '' || $createdAt < $cursor->watermark) {
                continue;
            }
            // Only the page's own posts — never visitor/other-admin wall posts.
            if (($row['from']['id'] ?? null) !== (string) $account->remote_account_id) {
                continue;
            }
            $media = $this->media($row);
            foreach ($media as $item) {
                if (is_array($item) && $item['id'] !== null) {
                    $photoIds[] = $item['id'];
                }
            }
            $rows[] = [$id, (string) ($row['message'] ?? ''), $createdAt, $media];
        }

        $fullRes = $this->fullResolutionPhotos($photoIds, (string) ($credentials['access_token'] ?? ''));

        $posts = [];
        $newest = null;
        foreach ($rows as [$id, $message, $createdAt, $media]) {
            $newest ??= $id;
            $posts[] = new NativePost($id, $message, $createdAt, array_map(
                static fn (NativeMedia|array $item): NativeMedia => $item instanceof NativeMedia
                    ? $item
                    : new NativeMedia($fullRes[$item['id']] ?? $item['src'], 'image'),
                $media,
            ), false, false);
        }

        return RecentPostsResult::ok($posts, $newest);
    }

    /**
     * Photos and videos from the post's attachments (album photos sit in
     * `subattachments`). Videos resolve to their MP4 `source`; photos are left as
     * {id, src} so the caller can swap the ~720px `src` for the full-size image.
     *
     * @param  array<string, mixed>  $row
     * @return list<NativeMedia|array{id: string|null, src: string}>
     */
    private function media(array $row): array
    {
        $media = [];
        foreach ((array) ($row['attachments']['data'] ?? []) as $attachment) {
            foreach ((array) ($attachment['subattachments']['data'] ?? [$attachment]) as $item) {
                $type = $item['media_type'] ?? null;
                if ($type === 'video' && isset($item['media']['source'])) {
                    $media[] = new NativeMedia((string) $item['media']['source'], 'video', 'video/mp4');
                } elseif ($type === 'photo' && isset($item['media']['image']['src'])) {
                    $media[] = ['id' => isset($item['target']['id']) ? (string) $item['target']['id'] : null, 'src' => (string) $item['media']['image']['src']];
                }
            }
        }

        return $media;
    }

    /**
     * Largest rendition per photo id, batched through the `?ids=` lookup. Best
     * effort: a failed lookup leaves the caller on the attachment's own src.
     *
     * @param  list<string>  $photoIds
     * @return array<string, string>
     */
    private function fullResolutionPhotos(array $photoIds, string $token): array
    {
        $urls = [];
        foreach (array_chunk(array_values(array_unique($photoIds)), 50) as $chunk) {
            try {
                $response = $this->http->timeout(10)->connectTimeout(5)->acceptJson()
                    ->get($this->baseUrl().'/', ['ids' => implode(',', $chunk), 'fields' => 'images', 'access_token' => $token]);
            } catch (ConnectionException) {
                continue;
            }
            if ($response->failed()) {
                continue;
            }
            foreach ((array) $response->json() as $photoId => $photo) {
                $largest = collect((array) ($photo['images'] ?? []))->sortByDesc('width')->first();
                if (isset($largest['source'])) {
                    $urls[(string) $photoId] = (string) $largest['source'];
                }
            }
        }

        return $urls;
    }

    private function baseUrl(): string
    {
        return sprintf('https://graph.facebook.com/%s', (string) config('services.facebook.graph_version'));
    }
}
