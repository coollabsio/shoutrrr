<?php

declare(strict_types=1);

namespace App\Http\Controllers\ConnectedAccounts;

use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Services\ConnectedAccounts\LinkedIn\LinkedInOrganizationDiscovery;
use App\Support\InstanceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

/**
 * Connects LinkedIn Pages (Organizations) through a SEPARATE developer app
 * approved for the Community Management API. LinkedIn requires that product to
 * be the only one on its app, so Pages cannot share the personal `linkedin-openid`
 * app that `OAuthConnectionController` drives — hence a dedicated OAuth flow with
 * its own credentials (`services.linkedin-pages`) and organization-only scopes.
 *
 * The Community Management app has no OpenID/Sign-In product, so its token can't
 * read a member profile via /userinfo. That's fine: the flow only needs the
 * member token to enumerate administered Pages and then post as the organization.
 * The exchange is therefore done manually (like MetaConnectionController /
 * ThreadsTokenExchanger) rather than through Socialite's linkedin-openid driver.
 */
class LinkedInPageOAuthController extends Controller
{
    private const string SESSION_KEY = 'accounts.linkedin.connect';

    private const string AUTHORIZE_URL = 'https://www.linkedin.com/oauth/v2/authorization';

    private const string TOKEN_URL = 'https://www.linkedin.com/oauth/v2/accessToken';

    /**
     * Organization scopes only — reading/writing Page posts and enumerating the
     * member's administered Pages (organizationAcls). No `openid`: the Community
     * Management app has no Sign-In product to grant it.
     *
     * @var list<string>
     */
    private const array SCOPES = ['r_organization_social', 'w_organization_social', 'rw_organization_admin'];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly LinkedInOrganizationDiscovery $organizations,
        private readonly InstanceSettings $settings,
    ) {}

    /**
     * Whether the dedicated Community Management app credentials are configured.
     */
    public static function pagesAppConfigured(): bool
    {
        return filled(config('services.linkedin-pages.client_id'))
            && filled(config('services.linkedin-pages.client_secret'));
    }

    public function redirect(Request $request): SymfonyRedirectResponse
    {
        $this->abortUnlessAvailable($request);

        // Stateless, like MetaConnectionController: the `auth` middleware plus the
        // explicit POST on the picker are the CSRF gates. `state` is echoed for
        // OAuth hygiene but not verified, since session state is unreliable behind
        // TLS-terminating proxies/tunnels.
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => (string) config('services.linkedin-pages.client_id'),
            'redirect_uri' => route('accounts.linkedin-pages.callback'),
            'state' => Str::random(40),
            'scope' => implode(' ', self::SCOPES),
        ]);

        return redirect()->away(self::AUTHORIZE_URL.'?'.$query);
    }

    public function callback(Request $request): RedirectResponse|InertiaResponse
    {
        $this->abortUnlessAvailable($request);

        if ($request->filled('error')) {
            Log::warning('LinkedIn Pages OAuth provider returned an error.', [
                'error' => $request->query('error'),
                'error_description' => $request->query('error_description'),
            ]);

            return $this->failed($request->query('error') === 'user_cancelled_authorize'
                ? 'You declined to connect your LinkedIn Page.'
                : "LinkedIn couldn't complete the connection. Please try again.");
        }

        if (! $request->filled('code')) {
            return $this->failed("We couldn't connect your LinkedIn Page. Please try again.");
        }

        $token = $this->exchangeCodeForToken((string) $request->query('code'));

        if ($token === null) {
            return $this->failed("We couldn't connect your LinkedIn Page. Please try again.");
        }

        $organizations = $this->organizations->administeredOrganizations($token['accessToken']);

        if ($organizations === []) {
            return $this->failed(
                "We couldn't find any LinkedIn Pages you administer. Only Pages where you're an "
                .'admin can be connected — check your role on the Page, then try again.',
            );
        }

        $stashedOrganizations = [];
        foreach ($organizations as $organization) {
            $stashedOrganizations[$organization->id] = [
                'id' => $organization->id,
                'urn' => $organization->urn,
                'name' => $organization->name,
                'vanityName' => $organization->vanityName,
            ];
        }

        $request->session()->put(self::SESSION_KEY, [
            'organizations' => $stashedOrganizations,
            'accessToken' => $token['accessToken'],
            'refreshToken' => $token['refreshToken'],
            'tokenExpiresAt' => $token['expiresAt']?->toIso8601String(),
            'approvedScopes' => $token['scopes'],
        ]);

        return Inertia::render('accounts/connect-linkedin', [
            'organizations' => array_values($stashedOrganizations),
        ]);
    }

    /**
     * Exchange the authorization code for a member token using the Community
     * Management app's credentials. Returns null on any failure (logged).
     *
     * @return array{accessToken: string, refreshToken: ?string, expiresAt: ?CarbonImmutable, scopes: list<string>}|null
     */
    private function exchangeCodeForToken(string $code): ?array
    {
        $response = $this->http->asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => route('accounts.linkedin-pages.callback'),
            'client_id' => (string) config('services.linkedin-pages.client_id'),
            'client_secret' => (string) config('services.linkedin-pages.client_secret'),
        ]);

        if ($response->failed()) {
            Log::warning('LinkedIn Pages token exchange failed.', [
                'status' => $response->status(),
                'error' => $response->json('error'),
                'error_description' => $response->json('error_description'),
            ]);

            return null;
        }

        $accessToken = (string) $response->json('access_token');

        if ($accessToken === '') {
            return null;
        }

        $expiresIn = (int) $response->json('expires_in');
        $refreshToken = $response->json('refresh_token');

        return [
            'accessToken' => $accessToken,
            'refreshToken' => is_string($refreshToken) && $refreshToken !== '' ? $refreshToken : null,
            'expiresAt' => $expiresIn > 0 ? Date::now()->addSeconds($expiresIn)->toImmutable() : null,
            'scopes' => $this->parseScopes($response->json('scope')),
        ];
    }

    /**
     * LinkedIn returns granted scopes as a comma- or space-separated string.
     *
     * @return list<string>
     */
    private function parseScopes(mixed $scope): array
    {
        if (! is_string($scope) || $scope === '') {
            return [];
        }

        return array_values(array_filter(preg_split('/[\s,]+/', $scope) ?: []));
    }

    /**
     * The Pages flow is only reachable when the operator has both turned on the
     * Community Management ("LinkedIn Pages") toggle and configured the dedicated
     * app. Anything else 404s — there's no personal-account fallback here.
     */
    private function abortUnlessAvailable(Request $request): void
    {
        $request->user()->can('create', ConnectedAccount::class) ?: abort(403);

        if (! $this->settings->linkedinCommunityManagementEnabled() || ! self::pagesAppConfigured()) {
            abort(404);
        }
    }

    private function failed(string $message): RedirectResponse
    {
        return redirect()->route('accounts.index')->with('error', $message);
    }
}
