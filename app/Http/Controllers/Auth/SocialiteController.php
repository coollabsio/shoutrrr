<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialProvider;
use App\Exceptions\SocialAuthException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\SocialiteService;
use App\Support\ProductHome;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SocialiteController extends Controller
{
    public function __construct(private readonly SocialiteService $socialite) {}

    public function redirect(Request $request, string $provider): Response
    {
        $resolved = $this->resolveProvider($provider);

        // Only the guest login/signup flow consumes this; the authenticated
        // linking flow never reads it, so don't leave it dangling in the session.
        if (! $request->user() && $request->filled('invitation')) {
            $request->session()->put('socialite.invitation', $request->string('invitation')->toString());
        }

        return $this->driver($resolved)->redirect();
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $resolved = $this->resolveProvider($provider);

        try {
            $oauthUser = $this->driver($resolved)->user();
        } catch (Throwable) {
            return redirect()->route('login')
                ->with('error', "Unable to sign in with {$resolved->label()}. Please try again.");
        }

        if ($authUser = $request->user()) {
            try {
                $this->socialite->linkToUser($authUser, $resolved, $oauthUser);
            } catch (SocialAuthException $e) {
                return redirect()->route('connections.edit')->with('error', $e->getMessage());
            }

            return redirect()->route('connections.edit')
                ->with('success', "{$resolved->label()} connected.");
        }

        try {
            $this->socialite->loginOrRegister(
                $resolved,
                $oauthUser,
                $request->session()->pull('socialite.invitation'),
            );
        } catch (SocialAuthException $e) {
            return redirect()->route('login')->with('error', $e->getMessage());
        }

        $user = auth()->user();

        return redirect()->intended(ProductHome::url($user instanceof User ? $user : null));
    }

    private function resolveProvider(string $provider): SocialProvider
    {
        return SocialProvider::fromEnabled($provider) ?? abort(404);
    }

    /**
     * Resolve the Socialite driver for a provider, pinned to the social-login
     * callback URL. X and LinkedIn share their Socialite config (`services.x`,
     * `services.linkedin-openid`) with the connected-accounts posting feature,
     * whose `redirect` points at `/accounts/callback/*`. Overriding the redirect
     * URL here keeps login pointed at `/auth/{provider}/callback`.
     */
    private function driver(SocialProvider $provider): AbstractProvider
    {
        $driver = Socialite::driver($provider->socialiteDriver());

        if (! $driver instanceof AbstractProvider) {
            abort(404);
        }

        return $driver->redirectUrl(route('auth.socialite.callback', $provider->value));
    }
}
