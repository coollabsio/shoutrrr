<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Dto\Publishing\LinkedInOrgLookupResult;
use App\Models\ConnectedAccount;
use App\Services\Publishing\Connectors\LinkedInConnector;
use App\Support\LinkedInOrg;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Resolve a user-supplied LinkedIn organization reference (company URL, vanity
 * slug, org URN, or numeric id) to a canonical `urn:li:organization:{id}` plus
 * its exact `localizedName` for emitting an `@[Name](urn:li:organization:ID)`
 * mention tag.
 *
 * Partner-gating caveat: the vanity-name lookup endpoint requires the
 * `rw_organization_admin` scope from the partner-gated Community Management API
 * product. Apps without it get a 403 — this resolver surfaces that as
 * `gated`, which is exactly what the `linkedin:org-lookup` probe detects.
 * Emitting the mention TAG itself only needs `w_member_social` (already held),
 * so a manually-entered URN still works even when lookup is gated.
 */
class LinkedInOrgResolver
{
    private const string ORGANIZATIONS_URL = 'https://api.linkedin.com/rest/organizations';

    public function __construct(
        private readonly TokenManager $tokenManager,
    ) {}

    /**
     * Resolve a reference to an org URN. References that already carry a numeric
     * id (URN, bare id, or `.../company/12345` URL) short-circuit with no API
     * call — but then the canonical name is unknown and stays null. A vanity
     * slug requires the (partner-gated) lookup endpoint.
     */
    public function resolve(ConnectedAccount $account, string $reference): LinkedInOrgLookupResult
    {
        $urn = LinkedInOrg::normalizeUrn($reference);

        if ($urn !== null) {
            return LinkedInOrgLookupResult::success($urn, name: null, status: 0);
        }

        $vanityName = LinkedInOrg::vanityName($reference);

        if ($vanityName === null) {
            return LinkedInOrgLookupResult::error("Could not derive an organization id or vanity name from \"{$reference}\".");
        }

        try {
            $token = (string) ($this->tokenManager->fresh($account)['access_token'] ?? '');
        } catch (Throwable $exception) {
            return LinkedInOrgLookupResult::error('Could not obtain a LinkedIn access token: '.$exception->getMessage());
        }

        if ($token === '') {
            return LinkedInOrgLookupResult::error('LinkedIn access token unavailable; reconnect the account.');
        }

        try {
            $response = Http::withToken($token)
                ->withHeaders([
                    'LinkedIn-Version' => $this->apiVersion(),
                    'X-Restli-Protocol-Version' => '2.0.0',
                ])
                ->acceptJson()
                ->get(self::ORGANIZATIONS_URL, [
                    'q' => 'vanityName',
                    'vanityName' => $vanityName,
                ]);
        } catch (ConnectionException $exception) {
            return LinkedInOrgLookupResult::error('LinkedIn request failed: '.$exception->getMessage());
        }

        $status = $response->status();

        // 403 = the partner-gated Community Management API wall (missing
        // rw_organization_admin). This is the probe's primary signal.
        if ($status === 403) {
            return LinkedInOrgLookupResult::gated($status);
        }

        if ($status === 404) {
            return LinkedInOrgLookupResult::notFound($status);
        }

        if ($response->failed()) {
            $message = (string) ($response->json('message') ?? 'LinkedIn organization lookup failed.');

            return LinkedInOrgLookupResult::error($message, $status);
        }

        /** @var list<array{id?: int|string, localizedName?: string, vanityName?: string}> $elements */
        $elements = (array) ($response->json('elements') ?? []);
        $first = $elements[0] ?? null;

        if (! is_array($first) || ! isset($first['id'])) {
            return LinkedInOrgLookupResult::notFound($status);
        }

        $name = isset($first['localizedName']) ? (string) $first['localizedName'] : null;

        return LinkedInOrgLookupResult::success('urn:li:organization:'.$first['id'], $name, $status);
    }

    private function apiVersion(): string
    {
        return (string) config('services.linkedin-openid.api_version', LinkedInConnector::DEFAULT_VERSION);
    }
}
