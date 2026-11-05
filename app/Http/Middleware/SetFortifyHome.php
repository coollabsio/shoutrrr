<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ProductHome;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Point Fortify's static home path at the signed-in user's product mode.
 * Login and registration responses read the user after authentication, because
 * this middleware still sees a guest on those requests.
 */
class SetFortifyHome
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            config(['fortify.home' => ProductHome::url($user, absolute: false)]);
        }

        return $next($request);
    }
}
