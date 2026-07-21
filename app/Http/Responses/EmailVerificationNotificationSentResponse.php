<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\EmailVerificationNotificationSentResponse as EmailVerificationNotificationSentResponseContract;

/**
 * Replaces Fortify's default response (which flashes a `status` string the app
 * doesn't surface) with a `success` flash. The auth layout's FlashListener turns
 * that into a toast, matching how the rest of the app confirms actions.
 */
class EmailVerificationNotificationSentResponse implements EmailVerificationNotificationSentResponseContract
{
    /**
     * The `Responsable` contract types `$request` as mixed, so the parameter
     * stays untyped to remain signature-compatible.
     *
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 202);
        }

        return back()->with('success', 'A new verification link has been sent to your email address.');
    }
}
