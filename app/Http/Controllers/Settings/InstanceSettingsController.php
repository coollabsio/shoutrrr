<?php

namespace App\Http\Controllers\Settings;

use App\Enums\InstanceRole;
use App\Enums\Platform;
use App\Enums\UsageCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreInstanceOwnerRequest;
use App\Http\Requests\Settings\UpdateInstancePlatformsRequest;
use App\Http\Requests\Settings\UpdateInstancePollingSettingsRequest;
use App\Http\Requests\Settings\UpdateInstanceSettingsRequest;
use App\Models\UsageEvent;
use App\Models\UsagePeriodCounter;
use App\Models\User;
use App\Models\Workspace;
use App\Support\InstanceSettings;
use App\Support\UsageOperation;
use App\Support\UsagePricing;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

class InstanceSettingsController extends Controller
{
    public function edit(Request $request, InstanceSettings $settings): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user?->isInstanceOwner(), 403);

        $workspacesEnabled = (bool) config('kit.workspaces.enabled');
        $instanceSettings = $settings->all();

        if (! $workspacesEnabled) {
            $instanceSettings['workspace_creation_enabled'] = false;
        }

        return Inertia::render('settings/instance', [
            'settings' => $instanceSettings,
            'workspaces_enabled' => $workspacesEnabled,
        ]);
    }

    public function admins(Request $request): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user?->isInstanceOwner(), 403);

        $search = $request->string('search')->trim()->toString();

        $users = $search === ''
            ? collect()
            : User::query()
                ->select(['id', 'name', 'email'])
                ->whereNull('instance_role')
                ->whereLike('email', "%{$search}%")
                ->orderBy('email')
                ->limit(10)
                ->get()
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar,
                ]);

        return Inertia::render('settings/instance-admins', [
            'owners' => User::query()
                ->select(['id', 'name', 'email', 'created_at'])
                ->where('instance_role', InstanceRole::Owner->value)
                ->orderBy('email')
                ->get()
                ->map(fn (User $owner): array => [
                    'id' => $owner->id,
                    'name' => $owner->name,
                    'email' => $owner->email,
                    'avatar' => $owner->avatar,
                    'created_at' => $owner->created_at,
                ]),
            'users' => $users,
            'search' => $search,
        ]);
    }

    public function polling(Request $request, InstanceSettings $settings): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user?->isInstanceOwner(), 403);

        return Inertia::render('settings/instance-polling', [
            'settings' => $settings->polling(),
            'sections' => collect(['engagement', 'post_metrics', 'account_metrics'])
                ->mapWithKeys(fn (string $section): array => [
                    $section => array_map(
                        fn (Platform $platform): array => [
                            'platform' => $platform->value,
                            'label' => $platform->label(),
                        ],
                        Platform::pollingSectionPlatforms($section),
                    ),
                ])->all(),
        ]);
    }

    public function platforms(Request $request, InstanceSettings $settings): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user?->isInstanceOwner(), 403);

        $enabled = $settings->platformsEnabled();

        return Inertia::render('settings/instance-platforms', [
            'platforms' => array_map(fn (Platform $platform): array => [
                'platform' => $platform->value,
                'label' => $platform->label(),
                'enabled' => $enabled[$platform->value] ?? true,
                'configured' => $platform->isConfigured(),
            ], Platform::cases()),
        ]);
    }

    public function updatePlatforms(UpdateInstancePlatformsRequest $request, InstanceSettings $settings): RedirectResponse
    {
        $settings->update(['platforms_enabled' => $request->platformsEnabled()]);

        return back()->with('success', 'Platform settings updated.');
    }

    public function usage(Request $request, UsagePricing $pricing): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user?->isInstanceOwner(), 403);

        $workspaceId = $request->string('workspace')->trim()->toString();
        $workspaceId = $workspaceId === '' ? null : $workspaceId;
        $platform = $this->usagePlatformFilter($request);
        $now = CarbonImmutable::instance(Date::now());
        $currentPeriodStart = $now->startOfMonth()->toDateString();
        $previousPeriodStart = $now->subMonthNoOverflow()->startOfMonth()->toDateString();

        $workspaces = Workspace::query()
            ->select(['id', 'name'])
            ->whereIn('id', function ($query): void {
                $query->select('workspace_id')
                    ->from('usage_period_counters')
                    ->whereNotNull('workspace_id')
                    ->union(
                        UsageEvent::query()
                            ->select('workspace_id')
                            ->whereNotNull('workspace_id')
                            ->toBase(),
                    );
            })
            ->orderBy('name')
            ->get()
            ->map(fn (Workspace $workspace): array => [
                'id' => $workspace->id,
                'name' => $workspace->name,
            ]);

        $comparisonCounters = UsagePeriodCounter::query()
            ->with('workspace:id,name')
            ->whereIn('period_start', [$currentPeriodStart, $previousPeriodStart])
            ->when($workspaceId, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->when($platform, fn ($query) => $query->where('platform', $platform))
            ->get();

        $summaries = $comparisonCounters
            ->groupBy('workspace_id')
            ->map(function ($rows) use ($currentPeriodStart, $previousPeriodStart, $pricing): array {
                /** @var UsagePeriodCounter $first */
                $first = $rows->first();

                $currentRows = $rows->where('period_start', $currentPeriodStart);
                $previousRows = $rows->where('period_start', $previousPeriodStart);
                $currentTotalQuota = (int) $currentRows->sum('total_quota');
                $previousTotalQuota = (int) $previousRows->sum('total_quota');
                $currentEstimatedCostUsd = $this->estimateCountersCost($pricing, $currentRows);
                $previousEstimatedCostUsd = $this->estimateCountersCost($pricing, $previousRows);

                return [
                    'workspace' => [
                        'id' => $first->workspace_id,
                        'name' => $this->workspaceName($first->workspace),
                    ],
                    'current_event_count' => (int) $currentRows->sum('event_count'),
                    'current_total_quota' => $currentTotalQuota,
                    'previous_total_quota' => $previousTotalQuota,
                    'quota_delta' => $currentTotalQuota - $previousTotalQuota,
                    'quota_delta_percent' => $previousTotalQuota > 0
                        ? round((($currentTotalQuota - $previousTotalQuota) / $previousTotalQuota) * 100, 1)
                        : null,
                    'current_estimated_cost_usd' => $currentEstimatedCostUsd,
                    'previous_estimated_cost_usd' => $previousEstimatedCostUsd,
                    'estimated_cost_delta_usd' => round($currentEstimatedCostUsd - $previousEstimatedCostUsd, 6),
                    'publish_quota' => (int) $currentRows->where('category', UsageCategory::Publish->value)->sum('total_quota'),
                    'external_api_quota' => (int) $currentRows->where('category', UsageCategory::ExternalApi->value)->sum('total_quota'),
                    'api_request_quota' => (int) $currentRows->where('category', UsageCategory::ApiRequest->value)->sum('total_quota'),
                    'posts_quota' => (int) $currentRows->where('operation', UsageOperation::POST)->sum('total_quota'),
                ];
            })
            ->sortByDesc('current_total_quota')
            ->values()
            ->take(100);

        $counters = UsagePeriodCounter::query()
            ->with('workspace:id,name')
            ->when($workspaceId, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->when($platform, fn ($query) => $query->where('platform', $platform))
            ->orderByDesc('period_start')
            ->orderBy('workspace_id')
            ->orderBy('category')
            ->orderBy('platform')
            ->orderBy('operation')
            ->limit(100)
            ->get()
            ->map(fn (UsagePeriodCounter $counter): array => [
                'id' => $counter->id,
                'workspace' => [
                    'id' => $counter->workspace_id,
                    'name' => $this->workspaceName($counter->workspace),
                ],
                'period_start' => $counter->period_start,
                'period_end' => $counter->period_end,
                'category' => $counter->category,
                'platform' => $counter->platform,
                'operation' => $counter->operation,
                'event_count' => $counter->event_count,
                'total_quota' => $counter->total_quota,
                'pricing' => $pricing->estimate($counter->platform, $counter->operation, $counter->total_quota),
            ]);

        $errorEvents = UsageEvent::query()
            ->with('workspace:id,name')
            ->where('succeeded', false)
            ->when($workspaceId, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->when($platform, function ($query) use ($platform): void {
                if ($platform === 'none') {
                    $query->whereNull('platform');

                    return;
                }

                $query->where('platform', $platform);
            })
            ->latest('occurred_at')
            ->limit(50)
            ->get()
            ->map(fn (UsageEvent $event): array => [
                'id' => $event->id,
                'workspace' => [
                    'id' => $event->workspace_id,
                    'name' => $this->workspaceName($event->workspace),
                ],
                'category' => $event->category,
                'operation' => $event->operation,
                'platform' => $event->platform ?? 'none',
                'quota_weight' => $event->quota_weight,
                'succeeded' => $event->succeeded,
                'meta' => $event->meta,
                'occurred_at' => $event->occurred_at,
            ]);

        return Inertia::render('settings/instance-usage', [
            'workspace_options' => $workspaces,
            'platforms' => [
                ['value' => Platform::Bluesky->value, 'label' => 'Bluesky'],
                ['value' => Platform::X->value, 'label' => 'X / Twitter'],
                ['value' => Platform::LinkedIn->value, 'label' => 'LinkedIn'],
                ['value' => 'none', 'label' => 'No platform'],
            ],
            'filters' => [
                'workspace' => $workspaceId,
                'platform' => $platform,
            ],
            'comparison_periods' => [
                'current' => $currentPeriodStart,
                'previous' => $previousPeriodStart,
            ],
            'pricing_source' => config('usage_pricing.source_url'),
            'pricing_currency' => config('usage_pricing.platforms.x.currency', 'USD'),
            'x_usage_available' => (string) config('services.x.bearer_token', '') !== '',
            'summaries' => $summaries,
            'counters' => $counters,
            'error_events' => $errorEvents,
        ]);
    }

    public function xUsage(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user?->isInstanceOwner(), 403);

        $validated = $request->validate([
            'days' => ['sometimes', 'integer', 'min:1', 'max:90'],
        ]);

        $bearerToken = (string) config('services.x.bearer_token', '');

        if ($bearerToken === '') {
            return response()->json([
                'message' => 'Configure X_BEARER_TOKEN before fetching X API usage.',
            ], 422);
        }

        $days = (int) ($validated['days'] ?? 7);
        $cacheKey = 'instance-settings:x-usage:tweets:'.sha1($bearerToken).":{$days}";

        try {
            /** @var array{data: mixed, fetched_at: string, source: string} $usage */
            $usage = Cache::remember($cacheKey, now()->addMinutes(2), function () use ($bearerToken, $days): array {
                $response = Http::withToken($bearerToken)
                    ->acceptJson()
                    ->timeout(10)
                    ->get('https://api.x.com/2/usage/tweets', [
                        'days' => $days,
                        'usage.fields' => 'cap_reset_day,daily_client_app_usage,daily_project_usage,project_cap,project_id,project_usage',
                    ]);

                if ($response->failed()) {
                    $response->throw();
                }

                return [
                    'data' => $response->json('data'),
                    'fetched_at' => Date::now()->toIso8601String(),
                    'source' => 'https://api.x.com/2/usage/tweets',
                ];
            });
        } catch (RequestException $exception) {
            $response = $exception->response;

            return response()->json([
                'message' => 'Unable to fetch X API usage.',
                'status' => $response->status(),
                'error' => $response->json() ?? $response->body(),
            ], 502);
        }

        return response()->json($usage);
    }

    private function estimateCountersCost(UsagePricing $pricing, mixed $counters): float
    {
        $total = 0.0;

        foreach ($counters as $counter) {
            if (! $counter instanceof UsagePeriodCounter) {
                continue;
            }

            $estimate = $pricing->estimate($counter->platform, $counter->operation, $counter->total_quota);

            if ($estimate !== null) {
                $total += $estimate['estimated_cost_usd'];
            }
        }

        return round($total, 6);
    }

    private function usagePlatformFilter(Request $request): ?string
    {
        $platform = $request->string('platform')->trim()->toString();
        $allowed = collect(Platform::cases())
            ->map(fn (Platform $platform): string => $platform->value)
            ->push('none');

        return $allowed->contains($platform) ? $platform : null;
    }

    private function workspaceName(?Workspace $workspace): string
    {
        if ($workspace === null) {
            return 'Deleted workspace';
        }

        return $workspace->name;
    }

    public function update(UpdateInstanceSettingsRequest $request, InstanceSettings $settings): RedirectResponse
    {
        $settings->update($request->instanceSettings());

        return back()->with('success', 'Instance settings updated.');
    }

    public function updatePolling(UpdateInstancePollingSettingsRequest $request, InstanceSettings $settings): RedirectResponse
    {
        $settings->update($request->instancePollingSettings());

        return back()->with('success', 'Polling settings updated.');
    }

    public function destroyAdmin(Request $request, User $owner): RedirectResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user?->isInstanceOwner(), 403);
        abort_unless($owner->isInstanceOwner(), 404);

        if ($owner->is($user)) {
            return back()->withErrors(['owner' => 'You cannot remove yourself as an instance owner.']);
        }

        if (User::query()->where('instance_role', InstanceRole::Owner->value)->count() <= 1) {
            return back()->withErrors(['owner' => 'At least one instance owner is required.']);
        }

        $owner->update(['instance_role' => null]);

        return back()->with('success', 'Instance owner removed.');
    }

    public function storeAdmin(StoreInstanceOwnerRequest $request): RedirectResponse
    {
        User::query()
            ->where('email', $request->email())
            ->update(['instance_role' => InstanceRole::Owner->value]);

        return back()->with('success', 'Instance owner added.');
    }
}
