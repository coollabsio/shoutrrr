<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Services\ConnectedAccounts\AccountConnectLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Connecting an account always finishes in a browser. These endpoints hand the
 * agent the URL a human opens; the API never completes OAuth itself.
 */
class PlatformsController extends Controller
{
    public function index(AccountConnectLinks $links): JsonResponse
    {
        $this->authorize('viewAny', ConnectedAccount::class);

        return response()->json(['data' => $links->list()]);
    }

    public function connectUrl(Request $request, string $platform, AccountConnectLinks $links): JsonResponse
    {
        $this->authorize('create', ConnectedAccount::class);

        $validated = $request->validate([
            'identifier' => ['nullable', 'string', 'max:255'],
            'pds_url' => ['nullable', 'url', 'max:255'],
        ]);

        $link = $links->describe($platform, [
            'identifier' => $validated['identifier'] ?? null,
            'pds_url' => $validated['pds_url'] ?? null,
        ]);

        if (! $link['available'] || $link['url'] === null) {
            abort(422, $link['reason'] ?? 'That platform cannot be connected on this instance.');
        }

        return response()->json($link);
    }
}
