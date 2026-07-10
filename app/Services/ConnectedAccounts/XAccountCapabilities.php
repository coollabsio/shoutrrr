<?php

declare(strict_types=1);

namespace App\Services\ConnectedAccounts;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

class XAccountCapabilities
{
    private const string USER_URL = 'https://api.x.com/2/users/me';

    private const int STANDARD_TEXT_LENGTH = 280;

    private const int PREMIUM_TEXT_LENGTH = 25_000;

    public function __construct(private readonly HttpFactory $http) {}

    /**
     * @return array{x_premium: bool, max_text_length: int, verified_type: string|null, x_subscription_tier: string, x_subscription_checked_at?: string}
     */
    public function forAccessToken(?string $token): array
    {
        return $this->tryForAccessToken($token) ?? self::fromUserData([]);
    }

    /**
     * Read the subscription tier for the authenticated user. A failed request is
     * deliberately distinct from a free account so callers can retain a known
     * tier instead of downgrading an account during a transient X API failure.
     *
     * @return array{x_premium: bool, max_text_length: int, verified_type: string|null, x_subscription_tier: string, x_subscription_checked_at: string}|null
     */
    public function tryForAccessToken(?string $token): ?array
    {
        if ($token === null || $token === '') {
            return null;
        }

        try {
            $response = $this->http
                ->withToken($token)
                ->acceptJson()
                ->timeout(5)
                ->connectTimeout(3)
                ->get(self::USER_URL, [
                    'user.fields' => 'subscription_type,verified,verified_type',
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('Could not detect X account capabilities.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('X account capabilities lookup failed.', [
                'status' => $response->status(),
            ]);

            return null;
        }

        return [
            ...self::fromUserData((array) $response->json('data', [])),
            'x_subscription_checked_at' => Date::now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $user
     * @return array{x_premium: bool, max_text_length: int, verified_type: string|null, x_subscription_tier: string}
     */
    public static function fromUserData(array $user): array
    {
        $verifiedType = isset($user['verified_type']) ? strtolower((string) $user['verified_type']) : null;
        $subscriptionTier = match (strtolower((string) ($user['subscription_type'] ?? ''))) {
            'basic' => 'basic',
            'premium' => 'premium',
            'premiumplus', 'premium_plus' => 'premium_plus',
            default => 'free',
        };
        $isPremium = $subscriptionTier !== 'free';

        return [
            'x_premium' => $isPremium,
            'max_text_length' => $isPremium ? self::PREMIUM_TEXT_LENGTH : self::STANDARD_TEXT_LENGTH,
            'verified_type' => $verifiedType,
            'x_subscription_tier' => $subscriptionTier,
        ];
    }
}
