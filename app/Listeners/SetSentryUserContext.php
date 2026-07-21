<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Auth\Events\Authenticated;
use Sentry\State\Scope;

use function Sentry\configureScope;

/**
 * Attaches the authenticated user's id (and nothing else) to the current Sentry
 * scope so errors can be tied to a user for triage without sending PII. Fires on
 * the `Authenticated` event, which the session guard dispatches once per request
 * as it resolves the user. No-op unless a Sentry DSN is configured.
 */
class SetSentryUserContext
{
    public function handle(Authenticated $event): void
    {
        if (blank(config('sentry.dsn'))) {
            return;
        }

        $id = $event->user->getAuthIdentifier();

        configureScope(function (Scope $scope) use ($id): void {
            $scope->setUser(['id' => $id]);
        });
    }
}
