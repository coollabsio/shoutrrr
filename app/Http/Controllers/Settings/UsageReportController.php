<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\UsageEvent;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

class UsageReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user?->isInstanceOwner(), 403);

        $from = $request->date('from') ?? Date::now()->startOfMonth();
        $to = $request->date('to') ?? Date::now();

        // Whitelisted grouping column — never interpolate raw input into SQL.
        $column = match ($request->string('group_by')->toString()) {
            'workspace' => 'workspace_id',
            'platform' => 'platform',
            'operation' => 'operation',
            default => 'category',
        };

        $data = UsageEvent::query()
            ->whereBetween('occurred_at', [$from, $to])
            ->groupBy($column)
            ->selectRaw("{$column} as label, count(*) as event_count, sum(quota_weight) as total_quota")
            ->get()
            ->map(fn ($row): array => [
                'label' => $row->label,
                'event_count' => (int) $row->event_count,
                'total_quota' => (int) $row->total_quota,
            ]);

        return response()->json([
            'group_by' => $request->string('group_by')->toString() ?: 'category',
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'data' => $data,
        ]);
    }
}
