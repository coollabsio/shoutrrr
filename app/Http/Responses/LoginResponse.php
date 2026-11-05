<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Models\User;
use App\Support\ProductHome;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            return new JsonResponse(['two_factor' => false]);
        }

        return redirect()->intended($this->home($request));
    }

    /**
     * @param  Request  $request
     */
    protected function home($request): string
    {
        $user = $request->user();

        return ProductHome::url($user instanceof User ? $user : null, absolute: false);
    }
}
