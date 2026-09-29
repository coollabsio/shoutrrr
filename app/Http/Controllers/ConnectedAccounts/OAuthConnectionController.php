<?php

declare(strict_types=1);

namespace App\Http\Controllers\ConnectedAccounts;

use App\Dto\ConnectedAccount\ConnectedAccountData;
use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Services\ConnectedAccounts\AccountConnectionService;
use App\Services\ConnectedAccounts\Threads\ThreadsTokenExchanger;
use App\Services\ConnectedAccounts\XAccountCapabilities;
use App\Support\InstanceSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class OAuthConnectionController extends Controller
{
    public function __construct(
        private readonly AccountConnectionService $connections,
        private readonly XAccountCapabilities $xCapabilities,
        private readonly ThreadsTokenExchanger $threadsExchanger,
        private readonly InstanceSettings $settings,
    ) {}

    public function redirect(Request $request, string $platform): Response
    {
        $resolved = $this->resolveOAuthPlatform($platform);

        $request->user()->can('create', ConnectedAccount::class) ?: abort(403);

        return $this->driver($resolved)->setScopes($this->scopesFor($resolved))->redirect();
    }

    public function callback(Request $request, string $platform): RedirectResponse
    {
        $resolved = $this->resolveOAuthPlatform($platform);

        $request->user()->can('create', ConnectedAccount::class) ?: abort(403);

        // The provider can bounce back with an error instead of a code — most
        // commonly when the user presses "Cancel" on the consent screen.
        if ($request->filled('error')) {
            Log::warning('Connected-account OAuth provider returned an error.', [
                'platform' => $resolved->value,
                'error' => $request->query('error'),
                'error_description' => $request->query('error_description'),
            ]);

            return $this->failed($this->denialMessage($resolved, (string) $request->query('error')));
        }

        try {
            $oauthUser = $this->driver($resolved)->user();
        } catch (Throwable $exception) {
            if ($exception instanceof InvalidStateException && $this->hasSuccessfulConnectionFlash($request, $resolved)) {
                $request->session()->keep('success');

                return redirect()->route('accounts.index');
            }

            Log::warning('Connected-account OAuth callback failed.', [
                'platform' => $resolved->value,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return $this->failed($this->failureMessage($resolved, $exception));
        }

        if (! $oauthUser instanceof SocialiteUser) {
            return $this->failed("We couldn't read your {$resolved->label()} profile. Please try again.");
        }

        $data = ConnectedAccountData::fromSocialite($resolved, $oauthUser);

        if ($resolved === Platform::X) {
            // Only stamp a subscription tier we actually read from X. A transient
            // lookup failure must not fabricate a "free" tier that silently caps a
            // Premium account — leave capabilities unset so the account shows
            // "not checked" and the Refresh tier action can fill it in later.
            $capabilities = $this->xCapabilities->tryForAccessToken($data->accessToken);
            if ($capabilities !== null) {
                $data = $data->withCapabilities($capabilities);
            }
        }

        if ($resolved->supportsDirectMessages()) {
            // Record whether the provider actually granted the DM scope(s) this
            // app requested, so the Messages inbox only polls/sends through
            // accounts that can reach the DM API (others are silently excluded
            // rather than 403ing at poll time).
            $granted = array_values((array) $oauthUser->approvedScopes);
            $required = $this->directMessageScopeDeltas($resolved);
            $dmGranted = $required !== [] && array_intersect($required, $granted) !== [];

            $data = $data->withCapabilities([...($data->capabilities ?? []), 'dm_enabled' => $dmGranted]);
        }

        if ($resolved === Platform::Threads) {
            // The short-lived Threads token is useless for publishing, so a failed
            // long-lived exchange is a failed connection — redirect with a friendly
            // message rather than letting the exception escape as a 500.
            try {
                $long = $this->threadsExchanger->exchangeForLongLived((string) $data->accessToken);
            } catch (Throwable $exception) {
                Log::warning('Threads long-lived token exchange failed.', [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);

                return $this->failed($this->failureMessage($resolved, $exception));
            }

            $data = $data->withLongLivedToken($long['token'], $long['expiresAt']);
        }

        $this->connections->store($data, $request->user());

        return redirect()->route('accounts.index')
            ->with('success', $this->successMessage($resolved));
    }

    private function failed(string $message): RedirectResponse
    {
        return redirect()->route('accounts.index')->with('error', $message);
    }

    private function successMessage(Platform $platform): string
    {
        return "{$platform->label()} account connected.";
    }

    private function hasSuccessfulConnectionFlash(Request $request, Platform $platform): bool
    {
        return $request->session()->get('success') === $this->successMessage($platform);
    }

    /**
     * Friendly message for a provider-side denial/error redirect.
     */
    private function denialMessage(Platform $platform, string $error): string
    {
        return match ($error) {
            'access_denied' => "You declined to connect your {$platform->label()} account.",
            default => "{$platform->label()} couldn't complete the connection. Please try again.",
        };
    }

    /**
     * Map a token/profile-exchange failure to a friendly, platform-agnostic
     * message, with a generic fallback. The exact cause is in the logs.
     */
    private function failureMessage(Platform $platform, Throwable $exception): string
    {
        $message = $exception->getMessage();

        return match (true) {
            str_contains($message, 'scope') => "Your {$platform->label()} app is missing a required permission. Check the app's configured scopes/permissions, then try again.",
            str_contains($message, '401'), str_contains($message, '403'), str_contains($message, 'Unauthorized'), str_contains($message, 'Forbidden') => "{$platform->label()} refused the request. Check your {$platform->label()} app's credentials and permissions, then try again.",
            default => "We couldn't connect your {$platform->label()} account. Please try again.",
        };
    }

    /**
     * OAuth scopes to request for a platform. LinkedIn Pages/organizations run
     * through the dedicated Community Management app (LinkedInPageOAuthController),
     * so the personal LinkedIn app here only ever requests its base member scopes.
     *
     * @return list<string>
     */
    private function scopesFor(Platform $platform): array
    {
        $scopes = $platform->scopes();

        if ($platform->supportsDirectMessages() && $this->settings->directMessagesEnabled()) {
            $scopes = [...$scopes, ...$this->directMessageScopeDeltas($platform)];
        }

        return array_values(array_unique($scopes));
    }

    /**
     * OAuth scopes required to reach a platform's DM API, requested only when
     * the instance has opted into Messages DM scopes (see `scopesFor()`).
     * Bluesky has no OAuth scopes (app-password auth); LinkedIn/Threads/Discord
     * have no DM API at all, so both fall through to the empty default.
     *
     * @return list<string>
     */
    private function directMessageScopeDeltas(Platform $platform): array
    {
        return match ($platform) {
            Platform::X => ['dm.read', 'dm.write'],
            Platform::Instagram => ['instagram_manage_messages'],
            Platform::Facebook => ['pages_messaging'],
            default => [],
        };
    }

    private function resolveOAuthPlatform(string $platform): Platform
    {
        $resolved = Platform::tryFrom($platform);

        if (
            ! $resolved instanceof Platform
            || ! $resolved->supportsOAuth()
            || ! $resolved->isConfigured()
            || ! $resolved->isLaunched()
            || ! app(InstanceSettings::class)->platformAvailable($resolved)
            // Facebook/Instagram always go through the dedicated
            // MetaConnectionController Page-selection flow, never this
            // generic single-step route — even once launched.
            || $resolved->usesMetaConnectionFlow()
        ) {
            abort(404);
        }

        return $resolved;
    }

    /**
     * Resolve the Socialite driver with the callback URL derived from the
     * current request (not `services.*.redirect`), so the redirect_uri always
     * matches the host/port the app is actually served on — e.g. 127.0.0.1:8000
     * in local dev. This mirrors the social-login flow and avoids the
     * APP_URL-vs-real-host mismatch that breaks the OAuth exchange.
     */
    private function driver(Platform $platform): AbstractProvider
    {
        $driver = Socialite::driver((string) $platform->socialiteDriver());

        if (! $driver instanceof AbstractProvider) {
            abort(404);
        }

        return $driver->redirectUrl(route('accounts.callback', ['platform' => $platform->value]));
    }
}
