<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\GoogleBusinessProfileConnector;
use App\Services\Publishing\PostStatusRollup;
use App\Services\Publishing\TokenManager;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

class ReconcileGoogleBusinessProfileLocalPost implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    private const int MAX_POLLS = 5;

    public function __construct(public PostTarget $target) {}

    public function uniqueId(): string
    {
        return $this->target->id;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new RateLimited('google-business-profile')];
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 60, 120];
    }

    public function handle(GoogleBusinessProfileConnector $connector, TokenManager $tokens, PostStatusRollup $rollup): void
    {
        $target = $this->target->fresh() ?? $this->target;
        if ($target->platform !== Platform::GoogleBusinessProfile || $target->status !== PostTargetStatus::Published) {
            return;
        }

        $metadata = $connector->fetchState($target, $tokens->fresh($target->account()->firstOrFail()));
        if ($metadata === null) {
            return;
        }

        $remoteMetadata = [...($target->remote_metadata ?? []), ...$metadata];
        $polls = (int) ($remoteMetadata['reconcile_polls'] ?? 0) + 1;
        $remoteMetadata['reconcile_polls'] = $polls;
        $target->forceFill(['remote_metadata' => $remoteMetadata])->save();

        if (($metadata['state'] ?? null) === 'REJECTED') {
            $target->forceFill([
                'status' => PostTargetStatus::Failed->value,
                'error_kind' => ErrorKind::Validation->value,
                'error_message' => 'Google Business Profile rejected this Local Post.',
            ])->save();
            $rollup->recompute($target->post()->firstOrFail());

            return;
        }

        if (in_array($metadata['state'] ?? null, ['PROCESSING', 'SCHEDULED'], true) && $polls < self::MAX_POLLS) {
            self::dispatch($target->fresh())->delay(30);
        }
    }
}
