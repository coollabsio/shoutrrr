<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Post;
use App\Services\Metrics\AnalyticsReport;
use App\Support\InstanceSettings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get_analytics')]
#[Description('Get workspace analytics for the last N days (7 to 365, default 90): a summary of followers, engagement, and posts published with deltas, each account\'s follower series, published post markers, and the top and bottom posts by engagement. Engagement is likes plus comments plus reposts.')]
class GetAnalyticsTool extends WorkspaceTool
{
    public function handle(Request $request, InstanceSettings $settings, AnalyticsReport $report): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        if (! $settings->metricsEnabled()) {
            return Response::error('Analytics are disabled on this instance.');
        }

        if ($denied = $this->authorize($request, 'viewAny', Post::class)) {
            return $denied;
        }

        $validated = $request->validate([
            'days' => ['nullable', 'integer'],
        ]);

        $days = AnalyticsReport::clampDays((int) ($validated['days'] ?? AnalyticsReport::DEFAULT_DAYS));

        return Response::text(json_encode([
            'range_days' => $days,
            ...$report->build($days),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'days' => $schema->integer()
                ->description('Window size in days, clamped to 7 through 365. Defaults to 90.')
                ->min(AnalyticsReport::MIN_DAYS)
                ->max(AnalyticsReport::MAX_DAYS),
        ];
    }
}
