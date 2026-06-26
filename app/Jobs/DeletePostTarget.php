<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\PostTargetStatus;
use App\Models\PostTarget;
use App\Services\Publishing\PublishConnectorRegistry;
use App\Services\Publishing\TokenManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class DeletePostTarget implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    public function __construct(public PostTarget $target) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(PublishConnectorRegistry $registry, TokenManager $tokens): void
    {
        $target = $this->target->fresh() ?? $this->target;
        $this->target = $target;

        if ($target->status === PostTargetStatus::Deleted) {
            return;
        }

        $target->forceFill(['status' => PostTargetStatus::Deleting->value])->save();

        $credentials = $tokens->fresh($target->account()->firstOrFail());
        $registry->for($target->platform)->delete($target, $credentials);

        $target->forceFill(['status' => PostTargetStatus::Deleted->value])->save();
    }

    public function failed(Throwable $e): void
    {
        $target = $this->target->fresh() ?? $this->target;

        $target->forceFill([
            'status' => PostTargetStatus::Failed->value,
            'error_message' => Str::limit($e->getMessage(), 1000),
            'next_attempt_at' => null,
        ])->save();
    }
}
