<?php

declare(strict_types=1);

namespace App\Http\Controllers\ConnectedAccounts;

use App\Dto\ConnectedAccount\ConnectedAccountData;
use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Services\ConnectedAccounts\AccountConnectionService;
use App\Services\ConnectedAccounts\Meta\MetaAssetEnumerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Runs a single Facebook Login flow shared by every Meta Graph platform
 * (Facebook, Instagram), enumerates the user's Pages/linked IG assets, and
 * lets them pick which assets to connect as which platform.
 *
 * OAuth is intentionally stateless: this route is already behind `auth`, and
 * connecting still requires an explicit POST on the selection screen. Socialite
 * session "state" is unreliable behind TLS-terminating tunnels/proxies and also
 * races when Facebook (or the browser) hits the callback twice — the second hit
 * then fails with "authorization code has been used".
 */
class MetaConnectionController extends Controller
{
    private const string SESSION_KEY = 'accounts.meta.connect';

    public function __construct(
        private readonly MetaAssetEnumerator $enumerator,
        private readonly AccountConnectionService $connections,
    ) {}

    public function redirect(Request $request): Response
    {
        $this->abortUnlessMetaFlowAvailable();

        $request->user()->can('create', ConnectedAccount::class) ?: abort(403);

        // Drop any half-finished picker stash so a re-connect starts clean.
        $request->session()->forget(self::SESSION_KEY);
        $request->session()->save();

        // setScopes (not scopes): Socialite's Facebook driver defaults include
        // `email`, which Facebook Login now rejects as invalid for this app
        // type. We only want Page/IG Graph permissions from Platform::scopes().
        // stateless(): see class docblock — auth + selection POST is our CSRF gate.
        return $this->driver()
            ->stateless()
            ->setScopes($this->scopes())
            ->redirectUrl(route('accounts.meta.callback'))
            ->redirect();
    }

    /**
     * The user-facing entry point (`redirect`) is gated here so the Meta flow is
     * never advertised until Facebook is configured and launched. `callback` and
     * `store` are deliberately NOT gated on `isLaunched()`: they must stay
     * exercisable by tests while Facebook is built-but-unlaunched, and `store`
     * independently blocks any account creation for a non-launched platform
     * (`Rule::in(launchedMetaGraphPlatforms)`), so reaching them early can stash
     * tokens in the caller's own session but can never mutate data. The gate is
     * re-evaluated on the launch task, when it becomes a no-op anyway.
     */
    private function abortUnlessMetaFlowAvailable(): void
    {
        if (! Platform::Facebook->isConfigured() || Platform::availableMetaGraphPlatforms() === []) {
            abort(404);
        }
    }

    public function callback(Request $request): RedirectResponse|InertiaResponse
    {
        $request->user()->can('create', ConnectedAccount::class) ?: abort(403);

        if ($request->filled('error')) {
            Log::warning('Meta OAuth provider returned an error.', [
                'error' => $request->query('error'),
                'error_description' => $request->query('error_description'),
            ]);

            return $this->failed('You declined to connect your Facebook account.');
        }

        // Duplicate callback (Facebook/browser double-hit) after a successful
        // exchange: re-show the picker instead of flashing a false failure.
        if ($this->hasStashedAssets($request)) {
            return $this->renderAssetPicker($request);
        }

        if (! $request->filled('code')) {
            return $this->failed("We couldn't connect your Facebook account. Please try again.");
        }

        $code = (string) $request->query('code');
        $lock = Cache::lock('meta-oauth-code:'.hash('sha256', $code), 30);

        try {
            $lock->block(15);

            // Another concurrent callback may have finished while we waited.
            if ($this->hasStashedAssets($request)) {
                return $this->renderAssetPicker($request);
            }

            $oauthUser = $this->resolveOAuthUser($request);

            $longLived = $this->enumerator->exchangeForLongLivedToken((string) $oauthUser->token);
            $assets = $this->enumerator->listPages($longLived['token']);
        } catch (Throwable $exception) {
            // Code already redeemed by the winning parallel request that stashed assets.
            if ($this->isAuthorizationCodeUsed($exception) && $this->hasStashedAssets($request)) {
                return $this->renderAssetPicker($request);
            }

            Log::warning('Meta OAuth callback failed.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'session_id' => $request->session()->getId(),
                'has_code' => $request->filled('code'),
            ]);

            if ($this->isAuthorizationCodeUsed($exception)) {
                return $this->failed(
                    'Facebook already used this login attempt. Click Connect Facebook again to start a fresh connection.',
                );
            }

            return $this->failed("We couldn't connect your Facebook account. Please try again.");
        } finally {
            $lock->release();
        }

        $stashedAssets = [];
        foreach ($assets as $asset) {
            $stashedAssets[$asset->pageId] = [
                'pageId' => $asset->pageId,
                'pageName' => $asset->pageName,
                'pageAccessToken' => $asset->pageAccessToken,
                'igUserId' => $asset->igUserId,
                'igUsername' => $asset->igUsername,
                'igAvatarUrl' => $asset->igAvatarUrl,
            ];
        }

        $request->session()->put(self::SESSION_KEY, [
            'assets' => $stashedAssets,
            'userTokenExpiresAt' => $longLived['expiresAt']?->toIso8601String(),
        ]);
        $request->session()->save();

        return $this->renderAssetPicker($request);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->user()->can('create', ConnectedAccount::class) ?: abort(403);

        $stash = $request->session()->get(self::SESSION_KEY);
        /** @var array<string, array{pageId: string, pageName: string, pageAccessToken: string, igUserId: ?string, igUsername: ?string, igAvatarUrl: ?string}> $stashedAssets */
        $stashedAssets = is_array($stash) ? ($stash['assets'] ?? []) : [];

        $launchedPlatforms = array_map(
            fn (Platform $platform): string => $platform->value,
            Platform::launchedMetaGraphPlatforms(),
        );

        $validated = $request->validate([
            'selected' => ['required', 'array', 'min:1'],
            'selected.*.assetKey' => ['required', 'string', Rule::in(array_keys($stashedAssets))],
            'selected.*.platform' => ['required', 'string', Rule::in($launchedPlatforms)],
        ]);

        $created = 0;

        foreach ($validated['selected'] as $selection) {
            $asset = $stashedAssets[$selection['assetKey']];
            $platform = Platform::from($selection['platform']);

            // The asset must actually support the chosen platform — a linked IG
            // account is required for Instagram. The frontend only offers the
            // valid pairs (`availablePlatformsFor`), but a crafted request could
            // pick `instagram` for a Page with no linked IG account, which would
            // otherwise persist a ghost account with an empty remote id.
            if (! in_array($platform->value, $this->availablePlatformsFor($asset), true)) {
                throw ValidationException::withMessages([
                    'selected' => "{$platform->label()} is not available for the selected Page.",
                ]);
            }

            $this->connections->store(self::buildAccountData($asset, $platform), $request->user());
            $created++;
        }

        $request->session()->forget(self::SESSION_KEY);

        return redirect()->route('accounts.index')->with(
            'success',
            $created === 1 ? '1 account connected.' : "{$created} accounts connected.",
        );
    }

    /**
     * Pure mapping from a stashed Meta asset to the DTO AccountConnectionService
     * persists. Extracted as a static method so the Facebook/Instagram mapping
     * can be unit-tested directly, without going through the gated HTTP route
     * (launchedMetaGraphPlatforms() is empty — and the whole flow inert — until
     * Facebook launches).
     *
     * @param  array{pageId: string, pageName: string, pageAccessToken: string, igUserId: ?string, igUsername: ?string, igAvatarUrl: ?string}  $stashedAsset
     */
    public static function buildAccountData(array $stashedAsset, Platform $platform): ConnectedAccountData
    {
        if ($platform === Platform::Instagram) {
            return new ConnectedAccountData(
                platform: Platform::Instagram,
                remoteAccountId: (string) $stashedAsset['igUserId'],
                handle: '@'.$stashedAsset['igUsername'],
                displayName: $stashedAsset['igUsername'],
                avatarUrl: $stashedAsset['igAvatarUrl'],
                authMethod: 'oauth',
                // IG publishing/comments/insights all authenticate with the
                // linked Page's token — the IG user id is just the target node.
                accessToken: $stashedAsset['pageAccessToken'],
                capabilities: ['page_id' => $stashedAsset['pageId']],
            );
        }

        return new ConnectedAccountData(
            platform: Platform::Facebook,
            remoteAccountId: $stashedAsset['pageId'],
            handle: $stashedAsset['pageName'],
            displayName: $stashedAsset['pageName'],
            avatarUrl: null,
            authMethod: 'oauth',
            accessToken: $stashedAsset['pageAccessToken'],
            // Page tokens minted from a long-lived user token don't expire.
            tokenExpiresAt: null,
        );
    }

    /**
     * The union of the launched Meta platforms' scopes, deduped, so a single
     * Facebook Login only asks for the permissions a launched platform
     * actually needs.
     *
     * @return list<string>
     */
    private function scopes(): array
    {
        $scopes = [];

        foreach (Platform::availableMetaGraphPlatforms() as $platform) {
            array_push($scopes, ...$platform->scopes());
        }

        return array_values(array_unique($scopes));
    }

    /**
     * @param  array<string, array{pageId: string, pageName: string, pageAccessToken: string, igUserId: ?string, igUsername: ?string, igAvatarUrl: ?string}>  $stashedAssets
     * @return list<array{key: string, pageId: string, pageName: string, igUserId: ?string, igUsername: ?string, igAvatarUrl: ?string, platforms: list<string>}>
     */
    private function projectAssets(array $stashedAssets): array
    {
        $projected = [];

        foreach ($stashedAssets as $key => $asset) {
            $projected[] = [
                'key' => $key,
                'pageId' => $asset['pageId'],
                'pageName' => $asset['pageName'],
                'igUserId' => $asset['igUserId'],
                'igUsername' => $asset['igUsername'],
                'igAvatarUrl' => $asset['igAvatarUrl'],
                'platforms' => $this->availablePlatformsFor($asset),
            ];
        }

        return $projected;
    }

    /**
     * @param  array{igUserId: ?string}  $asset
     * @return list<string>
     */
    private function availablePlatformsFor(array $asset): array
    {
        $available = Platform::availableMetaGraphPlatforms();
        $platforms = [];

        if (in_array(Platform::Facebook, $available, true)) {
            $platforms[] = Platform::Facebook->value;
        }

        if (in_array(Platform::Instagram, $available, true) && $asset['igUserId'] !== null) {
            $platforms[] = Platform::Instagram->value;
        }

        return $platforms;
    }

    private function failed(string $message): RedirectResponse
    {
        return redirect()->route('accounts.index')->with('error', $message);
    }

    private function hasStashedAssets(Request $request): bool
    {
        $stash = $request->session()->get(self::SESSION_KEY);

        return is_array($stash) && is_array($stash['assets'] ?? null) && $stash['assets'] !== [];
    }

    private function renderAssetPicker(Request $request): InertiaResponse
    {
        /** @var array{assets: array<string, array{pageId: string, pageName: string, pageAccessToken: string, igUserId: ?string, igUsername: ?string, igAvatarUrl: ?string}>} $stash */
        $stash = $request->session()->get(self::SESSION_KEY);

        return Inertia::render('accounts/connect-meta', [
            'assets' => $this->projectAssets($stash['assets']),
        ]);
    }

    private function isAuthorizationCodeUsed(Throwable $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'authorization code has been used')
            || str_contains($message, 'code has been used')
            || str_contains($message, '36009');
    }

    /**
     * Exchange the Facebook authorization code for a Socialite user.
     *
     * Always stateless: see class docblock. Auth middleware + the selection
     * POST are the real CSRF gates for this account-linking flow.
     */
    private function resolveOAuthUser(Request $request): SocialiteUser
    {
        $oauthUser = $this->driver()
            ->stateless()
            ->redirectUrl(route('accounts.meta.callback'))
            ->user();

        if (! $oauthUser instanceof SocialiteUser) {
            throw new RuntimeException('Facebook OAuth user payload was not a Socialite user.');
        }

        return $oauthUser;
    }

    private function driver(): AbstractProvider
    {
        $driver = Socialite::driver('facebook');

        if (! $driver instanceof AbstractProvider) {
            abort(404);
        }

        $version = config('services.facebook.graph_version');

        if (is_string($version) && $version !== '' && method_exists($driver, 'usingGraphVersion')) {
            $driver->usingGraphVersion($version);
        }

        // Avoid requesting profile fields that need the `email` scope we no longer ask for.
        if (method_exists($driver, 'fields')) {
            $driver->fields(['id', 'name', 'picture.width(1920)']);
        }

        return $driver;
    }
}
