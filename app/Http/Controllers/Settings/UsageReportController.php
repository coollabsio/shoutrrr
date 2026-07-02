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

        // Validate up front so unparseable dates return 422, not a Carbon 500.
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $from = $request->date('from') ?? Date::now()->startOfMonth();
        // Span the whole end day; a bare date parses to midnight and would otherwise
        // exclude every event recorded after 00:00:00 on that day.
        $to = ($request->date('to') ?? Date::now())->endOfDay();

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
            ->toBase()
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
