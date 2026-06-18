<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Exceptions\TokenRefreshException;
use App\Models\PostTarget;
use App\Models\PostTargetMetric;
use App\Services\Metrics\MetricsConnectorRegistry;
use App\Services\Publishing\TokenManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Date;

class CapturePostTargetMetrics implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public PostTarget $target) {}

    public function handle(MetricsConnectorRegistry $registry, TokenManager $tokens): void
    {
        if (! config('metrics.enabled')) {
            return;
        }

        $target = $this->target->fresh();

        if ($target === null) {
            return;
        }

        $account = $target->account()->withoutGlobalScopes()->first();

        if ($account === null) {
            return;
        }

        try {
            $credentials = $account->platform === Platform::X ? $tokens->fresh($account) : [];
        } catch (TokenRefreshException) {
            $this->record($target, MetricsStatus::Failed);

            return;
        }

        $result = $registry->for($target->platform)->fetchPost($account, $target, $credentials);

        if ($result->isOk()) {
            $now = Date::now();

            PostTargetMetric::updateOrCreate(
                ['post_target_id' => $target->id, 'captured_at' => $now],
                [
                    'likes' => $result->likes,
                    'comments' => $result->comments,
                    'reposts' => $result->reposts,
                    'impressions' => $result->impressions,
                ],
            );

            $target->forceFill([
                'likes' => $result->likes,
                'comments' => $result->comments,
                'reposts' => $result->reposts,
                'impressions' => $result->impressions,
                'metrics_status' => $result->status->value,
                'metrics_captured_at' => $now,
            ])->save();

            return;
        }

        $this->record($target, $result->status);
    }

    private function record(PostTarget $target, MetricsStatus $status): void
    {
        $target->forceFill([
            'metrics_status' => $status->value,
            'metrics_captured_at' => Date::now(),
        ])->save();
    }
}
