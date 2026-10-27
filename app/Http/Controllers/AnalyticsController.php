<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Models\Post;
use App\Services\Metrics\AnalyticsReport;
use App\Support\InstanceSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public function index(Request $request, InstanceSettings $settings, AnalyticsReport $report): Response
    {
        abort_unless($request->user()->can('viewAny', Post::class), 403);

        $days = AnalyticsReport::clampDays($request->integer('days', AnalyticsReport::DEFAULT_DAYS));

        return Inertia::render('analytics/index', [
            ...$report->build($days),
            'rangeDays' => $days,
            'polling' => [
                'post_metrics_enabled' => collect(Platform::cases())
                    ->mapWithKeys(fn (Platform $platform): array => [
                        $platform->value => $settings->postMetricsPollingEnabled($platform),
                    ])
                    ->all(),
                'account_metrics_enabled' => collect(Platform::cases())
                    ->mapWithKeys(fn (Platform $platform): array => [
                        $platform->value => $settings->accountMetricsPollingEnabled($platform),
                    ])
                    ->all(),
            ],
        ]);
    }
}
