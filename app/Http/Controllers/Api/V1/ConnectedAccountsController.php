<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use Illuminate\Http\JsonResponse;

class ConnectedAccountsController extends Controller
{
    public function index(): JsonResponse
    {
        $accounts = ConnectedAccount::query()
            ->latest()
            ->get()
            ->map(fn (ConnectedAccount $account): array => [
                'id' => $account->id,
                'platform' => $account->platform->value,
                'platform_label' => $account->platform->label(),
                'handle' => $account->handle,
                'display_name' => $account->display_name,
                'status' => $account->status->value,
                'status_label' => $account->status->label(),
                'token_expires_at' => $account->token_expires_at?->toIso8601String(),
            ]);

        return response()->json(['accounts' => $accounts]);
    }
}
