<?php

namespace App\Providers;

use App\Enums\Platform;
use App\Listeners\BindWorkspaceToAccessToken;
use App\Listeners\SetCurrentWorkspaceOnLogin;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\ExceptionResponse;
use Inertia\Inertia;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Passport;
use Override;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

class AppServiceProvider extends ServiceProvider
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
        $this->configureDefaults();
        $this->configureErrorPages();
        $this->configureTrustedProxies();

        // OAuth tokens issued for the MCP/API integration. Without explicit
        // lifetimes Passport defaults to ~1 year, so a leaked bearer is
        // effectively permanent.
        Passport::tokensExpireIn(now()->addHours(8));
        Passport::refreshTokensExpireIn(now()->addDays(30));
        Passport::personalAccessTokensExpireIn(now()->addDays(30));

        RateLimiter::for('mcp', fn ($request) => Limit::perMinute(60)
            ->by($request->user()?->id ?: $request->ip()));

        // Per-platform throttle for outbound metrics-capture jobs so a large
        // account/post list can't trip the platforms' own rate limits.
        foreach (Platform::cases() as $platform) {
            RateLimiter::for("metrics-{$platform->value}", fn (): Limit => Limit::perMinute(30));
        }

        Gate::before(function (User $user, string $ability): ?bool {
            if (! str_starts_with($ability, 'workspace.')) {
                return null;
            }

            return $user->hasAllPermissions([$ability], Context::get('workspace_id'));
        });

        Event::listen(Login::class, SetCurrentWorkspaceOnLogin::class);
        Event::listen(AccessTokenCreated::class, BindWorkspaceToAccessToken::class);

        Passport::authorizationView(
            /** @param array<string, mixed> $parameters */
            function (array $parameters): Response {
                $user = request()->user();

                return response()->view('oauth.authorize', array_merge($parameters, [
                    'workspaces' => $user
                        ? $user->workspaceMemberships()->with('workspace')->get()
                            ->pluck('workspace')->filter()->values()
                        : collect(),
                ]));
            }
        );

    }

    /**
     * Trust reverse-proxy forwarding headers (X-Forwarded-Proto/-Host/-Port) so
     * redirects, asset URLs, and OAuth redirect_uri values use the public HTTPS
     * scheme when the app is served behind a TLS-terminating proxy (Coolify/
     * Traefik). Off unless TRUSTED_PROXIES is configured. Set here rather than in
     * bootstrap/app.php because the config repository is not yet bound while the
     * middleware closure runs.
     */
    protected function configureTrustedProxies(): void
    {
        $trustedProxies = config('app.trusted_proxies');

        if (is_string($trustedProxies) && $trustedProxies !== '') {
            TrustProxies::at($trustedProxies);
        }
    }

    /**
     * Render HTTP errors through the Inertia UI instead of Laravel's default
     * HTML error templates.
     */
    protected function configureErrorPages(): void
    {
        Inertia::handleExceptionsUsing(function (ExceptionResponse $response): ?ExceptionResponse {
            if ($response->request->is('api/*') || $response->request->expectsJson()) {
                return null;
            }

            $status = $this->errorPageStatus($response);

            if (! in_array($status, [403, 404, 405, 419, 500, 503], true)) {
                return null;
            }

            $response->response->setStatusCode($status);

            return $response->render('error', [
                'status' => $status,
            ])->withSharedData();
        });
    }

    /**
     * Browsers probing a URL with GET should see "not found" instead of being
     * told which unsafe methods exist at that path.
     */
    protected function errorPageStatus(ExceptionResponse $response): int
    {
        if (
            $response->exception instanceof MethodNotAllowedHttpException
            && in_array($response->request->method(), ['GET', 'HEAD'], true)
        ) {
            return 404;
        }

        return $response->statusCode();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        $this->guardAgainstProductionDebug();

        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Refuse to boot a production app with debug mode on — it would expose full
     * stack traces and Ignition to the public.
     */
    public function guardAgainstProductionDebug(): void
    {
        if (app()->isProduction() && config('app.debug')) {
            throw new RuntimeException('APP_DEBUG must be false in production. Refusing to boot.');
        }
    }
}
