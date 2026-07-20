<?php

declare(strict_types=1);

namespace App\Services\ConnectedAccounts\LinkedIn;

use App\Dto\ConnectedAccount\LinkedInOrganization;
use App\Services\Publishing\Connectors\LinkedInConnector;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Discovers the LinkedIn Organizations (Pages) a member administers, so the
 * connect flow can offer them as connectable accounts. Uses the Community
 * Management ACL finder + Organization batch lookup. Never throws — any
 * permission/network failure yields an empty list so the connect flow degrades
 * to personal-profile-only rather than erroring.
 */
class LinkedInOrganizationDiscovery
{
    private const string ACLS_URL = 'https://api.linkedin.com/rest/organizationAcls';

    private const string ORGANIZATIONS_URL = 'https://api.linkedin.com/rest/organizations';

    public function __construct(private readonly HttpFactory $http) {}

    /**
     * @return list<LinkedInOrganization>
     */
    public function administeredOrganizations(string $accessToken): array
    {
        $urns = $this->adminOrganizationUrns($accessToken);

        if ($urns === []) {
            return [];
        }

        return $this->resolveOrganizations($accessToken, $urns);
    }

    /**
     * @return list<string> organization URNs the member administers (APPROVED)
     */
    private function adminOrganizationUrns(string $accessToken): array
    {
        $urns = [];
        $start = 0;

        do {
            try {
                $response = $this->http
                    ->withToken($accessToken)
                    ->withHeaders($this->headers())
                    ->acceptJson()
                    ->get(self::ACLS_URL, [
                        'q' => 'roleAssignee',
                        'role' => 'ADMINISTRATOR',
                        'state' => 'APPROVED',
                        'count' => 20,
                        'start' => $start,
                    ]);
            } catch (ConnectionException) {
                return [];
            }

            if ($response->failed()) {
                return $urns;
            }

            $elements = (array) $response->json('elements', []);

            foreach ($elements as $element) {
                // Paginated responses use `organizationTarget`; non-paginated use `organization`.
                $urn = (string) ($element['organizationTarget'] ?? $element['organization'] ?? '');

                if ($urn !== '') {
                    $urns[] = $urn;
                }
            }

            $start += count($elements);
            $hasNext = $elements !== [] && ! empty($response->json('paging.links'));
        } while ($hasNext);

        return array_values(array_unique($urns));
    }

    /**
     * @param  list<string>  $urns
     * @return list<LinkedInOrganization>
     */
    private function resolveOrganizations(string $accessToken, array $urns): array
    {
        $ids = array_map(fn (string $urn): string => $this->urnId($urn), $urns);

        try {
            $response = $this->http
                ->withToken($accessToken)
                ->withHeaders($this->headers())
                ->acceptJson()
                ->get(self::ORGANIZATIONS_URL, ['ids' => 'List('.implode(',', $ids).')']);
        } catch (ConnectionException) {
            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $results = (array) $response->json('results', []);
        $statuses = (array) $response->json('statuses', []);
        $organizations = [];

        foreach ($ids as $id) {
            if (($statuses[$id] ?? 200) !== 200 || ! isset($results[$id])) {
                continue;
            }

            $result = (array) $results[$id];
            $organizations[] = new LinkedInOrganization(
                id: $id,
                urn: 'urn:li:organization:'.$id,
                name: (string) ($result['localizedName'] ?? $id),
                vanityName: (string) ($result['vanityName'] ?? $id),
            );
        }

        return $organizations;
    }

    private function urnId(string $urn): string
    {
        $segments = explode(':', $urn);

        return $segments[count($segments) - 1];
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'LinkedIn-Version' => (string) config('services.linkedin-openid.api_version', LinkedInConnector::DEFAULT_VERSION),
            'X-Restli-Protocol-Version' => '2.0.0',
        ];
    }
}
