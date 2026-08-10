<?php

declare(strict_types=1);

namespace App\Http\Controllers\ConnectedAccounts;

use App\Dto\ConnectedAccount\ConnectedAccountData;
use App\Dto\ConnectedAccount\GoogleBusinessProfileLocation;
use App\Enums\Platform;
use App\Exceptions\GoogleBusinessProfileDiscoveryException;
use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Services\ConnectedAccounts\AccountConnectionService;
use App\Services\ConnectedAccounts\GoogleBusinessProfile\GoogleBusinessProfileLocationDiscovery;
use App\Support\InstanceSettings;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GoogleBusinessProfileConnectionController extends Controller
{
    private const string OAUTH_SESSION_KEY = 'accounts.google_business_profile.oauth';
    private const string LOCATIONS_SESSION_KEY = 'accounts.google_business_profile.locations';
    private const string AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const string TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const string CONSENT_VERSION = '2026-08-10.google-business-profile-local-posts-v1';

    public function __construct(
        private readonly GoogleBusinessProfileLocationDiscovery $discovery,
        private readonly AccountConnectionService $connections,
        private readonly InstanceSettings $settings,
        private readonly HttpFactory $http,
    ) {}

    public function redirect(Request $request): RedirectResponse
    {
        $this->available($request);
        $state = Str::random(64);
        $request->session()->forget([self::OAUTH_SESSION_KEY, self::LOCATIONS_SESSION_KEY]);
        $request->session()->put(self::OAUTH_SESSION_KEY, ['state' => $state]);
        $request->session()->save();

        return redirect()->away(self::AUTH_URL.'?'.http_build_query([
            'client_id' => config('services.google_business_profile.client_id'),
            'redirect_uri' => route('accounts.google-business-profile.callback'),
            'response_type' => 'code',
            'scope' => implode(' ', Platform::GoogleBusinessProfile->scopes()),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]));
    }

    public function callback(Request $request): RedirectResponse|Response
    {
        $request->user()->can('create', ConnectedAccount::class) ?: abort(403);
        $oauth = $request->session()->get(self::OAUTH_SESSION_KEY, []);
        $locationsStash = $request->session()->get(self::LOCATIONS_SESSION_KEY, []);

        if (is_array($locationsStash) && isset($locationsStash['locations'])) {
            return $this->picker($locationsStash);
        }

        if ($request->filled('error') || ! is_array($oauth) || ! hash_equals((string) ($oauth['state'] ?? ''), (string) $request->query('state')) || ! $request->filled('code')) {
            return redirect()->route('accounts.index')->with('error', 'Google Business Profile connection was not completed.');
        }

        $token = $this->http->asForm()->post(self::TOKEN_URL, [
            'code' => $request->query('code'),
            'client_id' => config('services.google_business_profile.client_id'),
            'client_secret' => config('services.google_business_profile.client_secret'),
            'redirect_uri' => route('accounts.google-business-profile.callback'),
            'grant_type' => 'authorization_code',
        ]);

        if ($token->failed() || ! filled($token->json('access_token')) || ! filled($token->json('refresh_token'))) {
            return redirect()->route('accounts.index')->with('error', 'Google Business Profile did not return a reusable authorization grant.');
        }

        try {
            $result = $this->discovery->discover((string) $token->json('access_token'));
        } catch (GoogleBusinessProfileDiscoveryException $exception) {
            return redirect()->route('accounts.index')->with('error', $exception->issue->message);
        }

        $locations = array_column(array_map(fn (GoogleBusinessProfileLocation $location): array => $location->toBrowserArray(), $result->locations), null, 'key');
        $expiresIn = (int) $token->json('expires_in', 0);

        $request->session()->put(self::OAUTH_SESSION_KEY, [
            'tokens' => [
                'access_token' => $token->json('access_token'),
                'refresh_token' => $token->json('refresh_token'),
                'expires_at' => $expiresIn > 0 ? Date::now()->addSeconds($expiresIn)->toIso8601String() : null,
            ],
        ]);
        $locationsStash = ['locations' => $locations, 'issues' => array_map(fn ($issue) => $issue->toArray(), $result->issues)];
        $request->session()->put(self::LOCATIONS_SESSION_KEY, $locationsStash);

        return $this->picker($locationsStash);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->user()->can('create', ConnectedAccount::class) ?: abort(403);
        $oauth = $request->session()->get(self::OAUTH_SESSION_KEY, []);
        $stash = $request->session()->get(self::LOCATIONS_SESSION_KEY, []);
        $locations = is_array($stash) ? ($stash['locations'] ?? []) : [];
        $validated = $request->validate(['selected' => ['required','array','min:1'], 'selected.*' => ['string', Rule::in(array_keys($locations))], 'consent' => ['accepted']]);
        $selected = array_unique($validated['selected']);
        if (! is_array($oauth) || ! is_array($oauth['tokens'] ?? null) || ! filled($oauth['tokens']['access_token'] ?? null) || ! filled($oauth['tokens']['refresh_token'] ?? null)) {
            return redirect()->route('accounts.index')->with('error', 'Google Business Profile authorization has expired. Please connect again.');
        }

        foreach ($selected as $key) {
            if (($locations[$key]['canOperateLocalPost'] ?? false) !== true) {
                throw ValidationException::withMessages(['selected' => 'Selected location cannot operate Local Posts.']);
            }
        }

        DB::transaction(function () use ($selected, $locations, $oauth, $request): void {
            foreach ($selected as $key) {
                $location = $locations[$key];
                $location['consent'] = ['version' => self::CONSENT_VERSION, 'granted_at' => Date::now()->toIso8601String(), 'granted_by_user_id' => $request->user()->id];
                $expiresAt = filled($oauth['tokens']['expires_at'] ?? null) ? Date::parse($oauth['tokens']['expires_at'])->toImmutable() : null;
                $this->connections->store(new ConnectedAccountData(Platform::GoogleBusinessProfile, $key, $location['title'], $location['addressLabel'] ? $location['title'].' — '.$location['addressLabel'] : $location['title'], null, 'oauth', $oauth['tokens']['access_token'], $oauth['tokens']['refresh_token'], session: ['scope' => 'business.manage'], capabilities: ['google_business_profile' => $location], tokenExpiresAt: $expiresAt), $request->user());
            }
        });
        $request->session()->forget([self::OAUTH_SESSION_KEY, self::LOCATIONS_SESSION_KEY]);
        return redirect()->route('accounts.index')->with('success', count($selected).' Google Business Profile location(s) connected.');
    }

    private function available(Request $request): void
    {
        $request->user()->can('create', ConnectedAccount::class) ?: abort(403);

        if (! Platform::GoogleBusinessProfile->isConfigured() || ! $this->settings->platformAvailable(Platform::GoogleBusinessProfile)) {
            abort(404);
        }
    }

    /** @param array<string, mixed> $stash */
    private function picker(array $stash): Response
    {
        return Inertia::render('accounts/connect-google-business-profile', [
            'locations' => array_values($stash['locations'] ?? []),
            'readinessIssues' => $stash['issues'] ?? [],
        ]);
    }
}
