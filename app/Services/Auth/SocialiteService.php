<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\SocialProvider;
use App\Exceptions\SocialAuthException;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Workspace\WorkspaceProvisioningService;
use App\Support\InstanceSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Contracts\User as SocialiteUser;

class SocialiteService
{
    public function __construct(
        private readonly WorkspaceProvisioningService $provisioning,
        private readonly InstanceSettings $settings,
    ) {}

    /**
     * Guest path: log in an existing identity, link-and-login an existing
     * verified-email account, or register a brand-new user. Logs the user in.
     *
     * @throws SocialAuthException when an unverified email collides with an existing account
     */
    public function loginOrRegister(
        SocialProvider $provider,
        SocialiteUser $oauthUser,
        ?string $invitationToken,
    ): SocialiteResult {
        $existing = $this->findLinkedAccount($provider, $oauthUser);

        if ($existing && ! $existing->user) {
            // Orphaned linkage (the user was removed without the FK cascade
            // firing). Discard it and resolve the identity from scratch.
            $existing->delete();
            $existing = null;
        }

        if ($existing) {
            Auth::login($existing->user, remember: true);

            return new SocialiteResult($existing->user, wasRegistered: false);
        }

        $email = $oauthUser->getEmail();

        if (! $email) {
            throw SocialAuthException::missingEmail($provider->label());
        }

        $byEmail = User::where('email', $email)->first();

        if ($byEmail) {
            if (! $this->providerEmailIsVerified($provider, $oauthUser)) {
                throw SocialAuthException::emailTaken($provider->label());
            }

            $this->linkAccount($byEmail, $provider, $oauthUser);
            Auth::login($byEmail, remember: true);

            return new SocialiteResult($byEmail, wasRegistered: false);
        }

        if (! $this->settings->registrationsAllowed($invitationToken)) {
            throw SocialAuthException::registrationsDisabled();
        }

        try {
            $user = DB::transaction(function () use ($provider, $oauthUser, $email, $invitationToken): User {
                $user = User::create([
                    'name' => $oauthUser->getName() ?? $oauthUser->getNickname() ?? 'User',
                    'email' => $email,
                    'password' => null,
                ]);

                if ($this->providerEmailIsVerified($provider, $oauthUser)) {
                    $user->forceFill(['email_verified_at' => now()])->save();
                }

                $this->linkAccount($user, $provider, $oauthUser);
                $this->settings->claimOwnerIfMissing($user);
                $this->provisioning->provisionForNewUser($user, $invitationToken);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent OAuth callback created this identity (or a user with
            // this email) first. The transaction rolled back cleanly; recover by
            // logging into the now-existing account instead of erroring.
            $existing = $this->findLinkedAccount($provider, $oauthUser);
            $recovered = $existing ? $existing->user : User::where('email', $email)->first();

            if (! $recovered) {
                throw SocialAuthException::couldNotComplete($provider->label());
            }

            Auth::login($recovered, remember: true);

            return new SocialiteResult($recovered, wasRegistered: false);
        }

        Auth::login($user, remember: true);

        return new SocialiteResult($user->refresh(), wasRegistered: true);
    }

    /**
     * Authenticated path: link a provider identity to the current user.
     *
     * @throws SocialAuthException when the identity is already linked to another user
     */
    public function linkToUser(User $user, SocialProvider $provider, SocialiteUser $oauthUser): void
    {
        $existing = $this->findLinkedAccount($provider, $oauthUser);

        if ($existing && ! $existing->user->is($user)) {
            throw SocialAuthException::alreadyLinkedElsewhere($provider->label());
        }

        if ($existing) {
            return;
        }

        $this->linkAccount($user, $provider, $oauthUser);
    }

    private function findLinkedAccount(SocialProvider $provider, SocialiteUser $oauthUser): ?SocialAccount
    {
        return SocialAccount::query()
            ->where('provider', $provider->value)
            ->where('provider_id', $oauthUser->getId())
            ->first();
    }

    private function linkAccount(User $user, SocialProvider $provider, SocialiteUser $oauthUser): void
    {
        $user->socialAccounts()->create([
            'provider' => $provider->value,
            'provider_id' => $oauthUser->getId(),
            'name' => $oauthUser->getName(),
            'nickname' => $oauthUser->getNickname(),
            'avatar' => $oauthUser->getAvatar(),
        ]);
    }

    /**
     * Determine whether the OAuth provider reported a verified email address.
     *
     * Reads the `email_verified` flag from the provider's raw attribute bag.
     * The `Laravel\Socialite\Contracts\User` interface does not declare that
     * bag, but every concrete implementation exposes it via a public `$user`
     * property (see `Laravel\Socialite\AbstractUser`), so we read it
     * dynamically via `data_get()` rather than depending on a concrete class.
     *
     * The `email_verified` key matches Google's and LinkedIn's OpenID payloads.
     * X (OAuth 2.0) does not send that flag — it returns a `confirmed_email`,
     * which Socialite maps onto the user's email, so a present email means X has
     * already confirmed it. A missing or falsy value is treated as unverified.
     */
    private function providerEmailIsVerified(SocialProvider $provider, SocialiteUser $oauthUser): bool
    {
        if ($provider === SocialProvider::X) {
            return $oauthUser->getEmail() !== null && $oauthUser->getEmail() !== '';
        }

        return filter_var(
            data_get($oauthUser, 'user.email_verified', false),
            FILTER_VALIDATE_BOOL,
        );
    }
}
