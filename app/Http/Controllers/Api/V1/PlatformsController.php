<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Services\ConnectedAccounts\AccountConnectLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

/**
 * Connecting an account always finishes in a browser. These endpoints hand the
 * agent the URL a human opens; the API never completes OAuth itself.
 */
class PlatformsController extends Controller
{
    /**
     * Read-only keys see which platforms exist but get no start URLs; fetching a
     * URL that connects an account needs the write scope, like connect-url.
     */
    public function index(Request $request, AccountConnectLinks $links): JsonResponse
    {
        $this->authorize('viewAny', ConnectedAccount::class);

        $rows = $links->forWorkspace(Context::get('workspace_id'))->list();

        if ($request->user()?->tokenCant('write')) {
            $rows = array_map(fn (array $row): array => [...$row, 'url' => null], $rows);
        }

        return response()->json(['data' => $rows]);
    }

    public function connectUrl(Request $request, string $platform, AccountConnectLinks $links): JsonResponse
    {
        $this->authorize('create', ConnectedAccount::class);

        $validated = $request->validate([
            'identifier' => ['nullable', 'string', 'max:255'],
            'pds_url' => ['nullable', 'url', 'max:255'],
        ]);

        $link = $links->forWorkspace(Context::get('workspace_id'))->describe($platform, [
            'identifier' => $validated['identifier'] ?? null,
            'pds_url' => $validated['pds_url'] ?? null,
        ]);

        if (! $link['available'] || $link['url'] === null) {
            abort(422, $link['reason'] ?? 'That platform cannot be connected on this instance.');
        }

        return response()->json($link);
    }
}
