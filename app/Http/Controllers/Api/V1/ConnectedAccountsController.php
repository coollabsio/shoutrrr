<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Support\CursorPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConnectedAccountsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = ConnectedAccount::query()
            ->orderBy('id', 'desc')
            ->cursorPaginate($validated['per_page'] ?? 25)
            ->through(fn (ConnectedAccount $account): array => [
                'id' => $account->id,
                'platform' => $account->platform->value,
                'platform_label' => $account->platform->label(),
                'handle' => $account->handle,
                'display_name' => $account->display_name,
                'status' => $account->status->value,
                'status_label' => $account->status->label(),
                'token_expires_at' => $account->token_expires_at?->toIso8601String(),
            ]);

        return response()->json(CursorPage::make($paginator));
    }
}
