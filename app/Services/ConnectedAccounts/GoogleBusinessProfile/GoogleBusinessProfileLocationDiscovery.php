<?php

declare(strict_types=1);

namespace App\Services\ConnectedAccounts\GoogleBusinessProfile;

use App\Dto\ConnectedAccount\GoogleBusinessProfileDiscoveryResult;
use App\Dto\ConnectedAccount\GoogleBusinessProfileLocation;
use App\Dto\ConnectedAccount\GoogleBusinessProfileReadinessIssue;
use App\Enums\GoogleBusinessProfileReadinessIssueCode;
use App\Exceptions\GoogleBusinessProfileDiscoveryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\RateLimiter;

class GoogleBusinessProfileLocationDiscovery
{
    public const string ACCOUNT_MANAGEMENT_BASE = 'https://mybusinessaccountmanagement.googleapis.com/v1';

    public const string BUSINESS_INFORMATION_BASE = 'https://mybusinessbusinessinformation.googleapis.com/v1';

    public const string LOCATION_READ_MASK = 'name,title,storeCode,storefrontAddress,metadata';

    public function __construct(private readonly HttpFactory $http) {}

    public function discover(string $accessToken): GoogleBusinessProfileDiscoveryResult
    {
        try {
            $locations = [];
            foreach ($this->accounts($accessToken) as $account) {
                $accountName = $account['name'] ?? null;
                if (! is_string($accountName) || ! str_starts_with($accountName, 'accounts/')) {
                    throw $this->malformed('Google returned an account without a valid resource name.');
                }

                foreach ($this->locations($accessToken, $accountName) as $location) {
                    $locations[] = $this->location($accountName, $location);
                }
            }
        } catch (ConnectionException) {
            throw new GoogleBusinessProfileDiscoveryException(new GoogleBusinessProfileReadinessIssue(
                GoogleBusinessProfileReadinessIssueCode::NetworkFailure,
                'Google Business Profile could not be reached.',
            ));
        }

        return new GoogleBusinessProfileDiscoveryResult(
            $locations,
            $locations === [] ? [new GoogleBusinessProfileReadinessIssue(GoogleBusinessProfileReadinessIssueCode::ZeroLocations, 'No Google Business Profile locations were available.')] : [],
        );
    }

    /** @return list<array<string, mixed>> */
    private function accounts(string $accessToken): array
    {
        return $this->paginate(self::ACCOUNT_MANAGEMENT_BASE.'/accounts', $accessToken, 'accounts', []);
    }

    /** @return list<array<string, mixed>> */
    private function locations(string $accessToken, string $accountName): array
    {
        return $this->paginate(self::BUSINESS_INFORMATION_BASE.'/'.$accountName.'/locations', $accessToken, 'locations', ['readMask' => self::LOCATION_READ_MASK]);
    }

    /** @param array<string, string> $query @return list<array<string, mixed>> */
    private function paginate(string $url, string $accessToken, string $key, array $query): array
    {
        $items = [];
        $pageToken = null;

        $maxPages = str_contains($url, 'accountmanagement') ? 20 : 50;

        for ($page = 0; $page < $maxPages; $page++) {
            if (RateLimiter::tooManyAttempts('google-business-profile', 10)) {
                throw new GoogleBusinessProfileDiscoveryException(new GoogleBusinessProfileReadinessIssue(
                    GoogleBusinessProfileReadinessIssueCode::QuotaExceeded,
                    'Google Business Profile requests are temporarily rate limited.',
                ));
            }
            RateLimiter::hit('google-business-profile', 60);
            $response = $this->http->acceptJson()->withToken($accessToken)->get($url, [...$query, 'pageSize' => str_contains($url, 'accountmanagement') ? 20 : 100, ...($pageToken === null ? [] : ['pageToken' => $pageToken])]);
            if ($response->failed()) {
                throw new GoogleBusinessProfileDiscoveryException($this->issueFor($response));
            }

            $payload = $response->json();
            if (! is_array($payload) || ! is_array($payload[$key] ?? null)) {
                throw $this->malformed('Google returned an unexpected discovery response.');
            }

            foreach ($payload[$key] as $item) {
                if (! is_array($item)) {
                    throw $this->malformed('Google returned a malformed discovery item.');
                }
                $items[] = $item;
            }

            $pageToken = $payload['nextPageToken'] ?? null;
            if (! is_string($pageToken) || $pageToken === '') {
                return $items;
            }
        }

        throw $this->malformed('Google discovery pagination exceeded the safe limit.');
    }

    /** @param array<string, mixed> $location */
    private function location(string $accountName, array $location): GoogleBusinessProfileLocation
    {
        $name = $location['name'] ?? null;
        if (! is_string($name) || $name === '') {
            throw $this->malformed('Google returned a location without a resource name.');
        }
        $resourceName = str_starts_with($name, 'accounts/') ? $name : $accountName.'/'.$name;
        $metadata = is_array($location['metadata'] ?? null) ? $location['metadata'] : [];
        $address = is_array($location['storefrontAddress'] ?? null) ? $location['storefrontAddress'] : [];
        $addressLabel = isset($address['addressLines']) && is_array($address['addressLines']) ? implode(', ', array_filter($address['addressLines'], 'is_string')) : null;
        $eligible = ($metadata['canOperateLocalPost'] ?? false) === true;

        return new GoogleBusinessProfileLocation(
            $resourceName,
            $accountName,
            $resourceName,
            is_string($location['title'] ?? null) ? $location['title'] : $resourceName,
            is_string($location['storeCode'] ?? null) ? $location['storeCode'] : null,
            $addressLabel,
            is_string($metadata['mapsUri'] ?? null) ? $metadata['mapsUri'] : null,
            $eligible,
            $eligible ? [] : [new GoogleBusinessProfileReadinessIssue(GoogleBusinessProfileReadinessIssueCode::IneligibleLocation, 'This location cannot operate Local Posts.')],
        );
    }

    private function malformed(string $message): GoogleBusinessProfileDiscoveryException
    {
        return new GoogleBusinessProfileDiscoveryException(new GoogleBusinessProfileReadinessIssue(GoogleBusinessProfileReadinessIssueCode::MalformedResponse, $message));
    }

    private function issueFor(Response $response): GoogleBusinessProfileReadinessIssue
    {
        $error = $response->json('error');
        $message = is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : 'Google Business Profile discovery failed.';
        $status = is_array($error) && is_string($error['status'] ?? null) ? $error['status'] : null;
        $details = is_array($error) && is_array($error['details'] ?? null) ? $error['details'] : [];
        $reason = null;
        $service = null;

        foreach ($details as $detail) {
            if (! is_array($detail)) {
                continue;
            }

            $reason ??= is_string($detail['reason'] ?? null) ? $detail['reason'] : null;
            $service ??= is_string($detail['metadata']['service'] ?? null) ? $detail['metadata']['service'] : null;
        }

        $code = match (true) {
            $reason === 'SERVICE_DISABLED' || $status === 'SERVICE_DISABLED' => GoogleBusinessProfileReadinessIssueCode::ApiDisabled,
            $response->status() === 429 || $status === 'RESOURCE_EXHAUSTED' => GoogleBusinessProfileReadinessIssueCode::QuotaExceeded,
            $response->status() === 401 || $response->status() === 403 => GoogleBusinessProfileReadinessIssueCode::PermissionDenied,
            default => GoogleBusinessProfileReadinessIssueCode::UnknownGoogleError,
        };

        return new GoogleBusinessProfileReadinessIssue($code, $message, $service, $reason, $response->status());
    }
}
