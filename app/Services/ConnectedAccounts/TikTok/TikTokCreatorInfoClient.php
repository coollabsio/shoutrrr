<?php

declare(strict_types=1);

namespace App\Services\ConnectedAccounts\TikTok;

use App\Dto\ConnectedAccount\TikTokCreatorInfo;
use App\Enums\UsageCategory;
use App\Models\ConnectedAccount;
use App\Services\Usage\Concerns\TracksUsage;
use App\Support\UsageOperation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

class TikTokCreatorInfoClient
{
    use TracksUsage;

    private const string URL = 'https://open.tiktokapis.com/v2/post/publish/creator_info/query/';

    public function __construct(private readonly HttpFactory $http) {}

    /**
     * @throws TikTokApiException when TikTok answers with an error
     * @throws ConnectionException when TikTok cannot be reached
     */
    public function query(ConnectedAccount $account, string $accessToken): TikTokCreatorInfo
    {
        $response = $this->http->withToken($accessToken)->acceptJson()->timeout(10)->connectTimeout(5)
            ->withBody('{}', 'application/json; charset=UTF-8')
            ->post(self::URL);

        $this->meter(UsageCategory::ExternalApi, UsageOperation::CREATOR_INFO, $account, $response);

        $error = TikTokApiError::from($response);

        if ($error instanceof TikTokApiError) {
            throw new TikTokApiException($error);
        }

        return TikTokCreatorInfo::fromResponse((array) $response->json('data'));
    }
}
