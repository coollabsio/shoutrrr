<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\UiMode;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * Where a signed-in user lands. Agentic is the default; Classic is explicit.
 */
final class ProductHome
{
    public static function routeName(?User $user): string
    {
        return ($user?->uiMode() ?? UiMode::Agentic)->homeRoute();
    }

    public static function url(?User $user, bool $absolute = true): string
    {
        return route(self::routeName($user), absolute: $absolute);
    }

    public static function redirect(?User $user): RedirectResponse
    {
        return redirect()->to(self::url($user));
    }
}
