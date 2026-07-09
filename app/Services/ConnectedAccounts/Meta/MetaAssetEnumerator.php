<?php

declare(strict_types=1);

namespace App\Services\ConnectedAccounts\Meta;

use App\Dto\ConnectedAccount\MetaAsset;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Date;

class MetaAssetEnumerator
{
    public function __construct(private readonly HttpFactory $http) {}

    /**
     * Exchange a short-lived Facebook user token for a long-lived one, so that
     * Page tokens enumerated from `/me/accounts` come back non-expiring.
     *
     * @return array{token: string, expiresAt: ?CarbonImmutable}
     */
    public function exchangeForLongLivedToken(string $shortLivedToken): array
    {
        $response = $this->http->get($this->baseUrl().'/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('services.facebook.client_id'),
            'client_secret' => config('services.facebook.client_secret'),
            'fb_exchange_token' => $shortLivedToken,
        ]);

        $response->throw();

        $expiresIn = $response->json('expires_in');

        return [
            'token' => (string) $response->json('access_token'),
            'expiresAt' => $expiresIn !== null ? Date::now()->addSeconds((int) $expiresIn)->toImmutable() : null,
        ];
    }

    /**
     * Enumerate the user's managed Pages (+ linked Instagram Professional
     * accounts, when present) via `/me/accounts`, following `paging.next`
     * until the API stops returning one.
     *
     * @return list<MetaAsset>
     */
    public function listPages(string $longLivedUserToken): array
    {
        $assets = [];
        $url = $this->baseUrl().'/me/accounts';
        $query = [
            'fields' => 'id,name,access_token,category,tasks,instagram_business_account{id,username,profile_picture_url}',
            'access_token' => $longLivedUserToken,
        ];

        while ($url !== null) {
            $response = $this->http->get($url, $query);

            $response->throw();

            /** @var array{data?: list<array<string, mixed>>, paging?: array{next?: string}} $body */
            $body = (array) $response->json();

            foreach ($body['data'] ?? [] as $page) {
                $assets[] = $this->toAsset($page);
            }

            $url = $body['paging']['next'] ?? null;
            $query = [];
        }

        return $assets;
    }

    /**
     * @param  array<string, mixed>  $page
     */
    private function toAsset(array $page): MetaAsset
    {
        /** @var array{id?: string, username?: string, profile_picture_url?: string}|null $instagram */
        $instagram = $page['instagram_business_account'] ?? null;

        return new MetaAsset(
            pageId: (string) $page['id'],
            pageName: (string) $page['name'],
            pageAccessToken: (string) $page['access_token'],
            igUserId: $instagram['id'] ?? null,
            igUsername: $instagram['username'] ?? null,
            igAvatarUrl: $instagram['profile_picture_url'] ?? null,
        );
    }

    private function baseUrl(): string
    {
        return sprintf('https://graph.facebook.com/%s', config('services.facebook.graph_version'));
    }
}
