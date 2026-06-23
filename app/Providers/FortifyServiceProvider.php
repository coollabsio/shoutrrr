<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Enums\SocialProvider;
use App\Models\WorkspaceInvitation;
use App\Settings\InstanceSettings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Override;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[Override]
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(function (Request $request) {
            $settings = app(InstanceSettings::class);
            $canRegister = $settings->registrationsAllowed($request->query('invitation'));

            return Inertia::render('auth/login', [
                'canResetPassword' => Features::enabled(Features::resetPasswords()),
                'canRegister' => $canRegister,
                'registrationDisabledMessage' => $canRegister ? null : 'Registration is disabled for this instance.',
                'status' => $request->session()->get('status'),
                'providers' => SocialProvider::enabledProvidersWithLabels(),
                'invitation' => $request->query('invitation'),
                ...$this->defaultLoginCredentials(),
            ]);
        });

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/verify-email', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(function (Request $request) {
            $invitationToken = $request->query('invitation');
            $invitationToken = is_string($invitationToken) ? $invitationToken : null;

            if (! app(InstanceSettings::class)->registrationsAllowed($invitationToken)) {
                return redirect()->route('login');
            }

            $invitation = $invitationToken !== null
                ? WorkspaceInvitation::findByToken($invitationToken)
                : null;

            return Inertia::render('auth/register', [
                'passwordRules' => Password::defaults()->toPasswordRulesString(),
                'providers' => SocialProvider::enabledProvidersWithLabels(),
                'invitation' => $invitationToken,
                'invitationEmail' => $invitation?->isValid() ? $invitation->email : null,
            ]);
        });

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/two-factor-challenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));
    }

    /**
     * Get default login credentials for local development.
     *
     * @return array<string, array{email: string, password: string}>
     */
    private function defaultLoginCredentials(): array
    {
        if (! app()->isLocal()) {
            return [];
        }

        return [
            'defaultLogin' => [
                'email' => 'test@example.com',
                'password' => 'password',
            ],
        ];
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by($request->session()->get('login.id')));

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('passkeys', fn (Request $request) => Limit::perMinute(10)->by(
            ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
        ));
    }
}
