<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Exceptions\TokenRefreshException;
use App\Models\AccountMetric;
use App\Models\ConnectedAccount;
use App\Services\Metrics\MetricsConnectorRegistry;
use App\Services\Publishing\TokenManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Date;

class CaptureAccountMetrics implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public ConnectedAccount $account) {}

    public function handle(MetricsConnectorRegistry $registry, TokenManager $tokens): void
    {
        if (! config('metrics.enabled')) {
            return;
        }

        $account = $this->account->fresh();

        if ($account === null) {
            return;
        }

        try {
            $credentials = $account->platform === Platform::X ? $tokens->fresh($account) : [];
        } catch (TokenRefreshException) {
            $this->record($account, MetricsStatus::Failed);

            return;
        }

        $result = $registry->for($account->platform)->fetchAccount($account, $credentials);

        if ($result->isOk()) {
            AccountMetric::create([
                'connected_account_id' => $account->id,
                'captured_at' => Date::now(),
                'followers' => $result->followers,
                'following' => $result->following,
                'posts_count' => $result->postsCount,
                'raw' => $result->raw,
            ]);
        }

        $this->record($account, $result->status);
    }

    private function record(ConnectedAccount $account, MetricsStatus $status): void
    {
        $account->forceFill([
            'metrics_status' => $status->value,
            'metrics_captured_at' => Date::now(),
        ])->save();
    }
}
