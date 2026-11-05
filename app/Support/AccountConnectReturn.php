<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where a connect flow lands when it ends. A flow started from the Agentic home
 * (`?return=agentic`) goes back there, so the user continues with sync setup.
 * Every other flow goes to the Classic accounts page.
 */
final class AccountConnectReturn
{
    public const QUERY_VALUE = 'agentic';

    private const SESSION_KEY = 'accounts.connect_return';

    /**
     * Call when a connect flow starts. Always sets or clears the flag, so an
     * abandoned flow cannot change where a later flow lands.
     */
    public static function remember(Request $request): void
    {
        if ($request->query('return') === self::QUERY_VALUE) {
            $request->session()->put(self::SESSION_KEY, true);

            return;
        }

        $request->session()->forget(self::SESSION_KEY);
    }

    /**
     * Call when a connect flow ends, with success or with an error.
     */
    public static function redirect(): RedirectResponse
    {
        return session()->pull(self::SESSION_KEY) === true
            ? redirect()->route('agentic.home')
            : redirect()->route('accounts.index');
    }
}
