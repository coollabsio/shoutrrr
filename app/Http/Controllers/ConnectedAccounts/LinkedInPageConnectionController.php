<?php

declare(strict_types=1);

namespace App\Http\Controllers\ConnectedAccounts;

use App\Dto\ConnectedAccount\ConnectedAccountData;
use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Services\ConnectedAccounts\AccountConnectionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Persists the LinkedIn accounts (personal profile + administered Pages) the
 * user picked after OAuth. All selected accounts share the same member token —
 * LinkedIn mints no per-Page token; the member token acts on Pages it
 * administers.
 */
class LinkedInPageConnectionController extends Controller
{
    private const string SESSION_KEY = 'accounts.linkedin.connect';

    public function __construct(private readonly AccountConnectionService $connections) {}

    public function store(Request $request): RedirectResponse
    {
        $request->user()->can('create', ConnectedAccount::class) ?: abort(403);

        $stash = $request->session()->get(self::SESSION_KEY);

        if (! is_array($stash)) {
            return redirect()->route('accounts.index')->with('error', 'Your LinkedIn connection expired. Please try again.');
        }

        /** @var array<string, array{id: string, urn: string, name: string, vanityName: string}> $organizations */
        $organizations = $stash['organizations'] ?? [];

        $validated = $request->validate([
            'selected' => ['required', 'array', 'min:1'],
            'selected.*.type' => ['required', 'string', Rule::in(['person', 'organization'])],
            'selected.*.id' => ['required_if:selected.*.type,organization', 'nullable', 'string', Rule::in(array_keys($organizations))],
        ]);

        $token = (string) ($stash['accessToken'] ?? '');
        $refresh = $stash['refreshToken'] ?? null;
        $expiresAt = isset($stash['tokenExpiresAt'])
            ? CarbonImmutable::parse((string) $stash['tokenExpiresAt'])
            : null;

        /** @var array<int, string> $granted */
        $granted = (array) ($stash['approvedScopes'] ?? []);

        $user = $request->user();

        // Persist every selected account in one transaction so a mid-loop failure
        // can't leave the workspace with a partial set of connected accounts.
        DB::transaction(function () use ($validated, $organizations, $stash, $token, $refresh, $expiresAt, $granted, $user): void {
            foreach ($validated['selected'] as $selection) {
                if ($selection['type'] === 'person') {
                    $person = $stash['person'];
                    $data = new ConnectedAccountData(
                        platform: Platform::LinkedIn,
                        remoteAccountId: (string) $person['remoteAccountId'],
                        handle: (string) $person['handle'],
                        displayName: $person['displayName'] ?? null,
                        avatarUrl: $person['avatarUrl'] ?? null,
                        authMethod: 'oauth',
                        accessToken: $token,
                        refreshToken: $refresh,
                        capabilities: ['linkedin_account_type' => 'person', 'linkedin_engagement' => in_array('r_member_social_feed', $granted, true)],
                        tokenExpiresAt: $expiresAt,
                    );
                } else {
                    $organization = $organizations[$selection['id']];
                    $data = new ConnectedAccountData(
                        platform: Platform::LinkedIn,
                        remoteAccountId: (string) $organization['id'],
                        handle: (string) $organization['vanityName'],
                        displayName: (string) $organization['name'],
                        avatarUrl: null,
                        authMethod: 'oauth',
                        accessToken: $token,
                        refreshToken: $refresh,
                        capabilities: ['linkedin_account_type' => 'organization', 'linkedin_engagement' => in_array('r_organization_social', $granted, true)],
                        tokenExpiresAt: $expiresAt,
                    );
                }

                $this->connections->store($data, $user);
            }
        });

        $created = count($validated['selected']);
        $request->session()->forget(self::SESSION_KEY);

        return redirect()->route('accounts.index')->with(
            'success',
            $created === 1 ? '1 account connected.' : "{$created} accounts connected.",
        );
    }
}
