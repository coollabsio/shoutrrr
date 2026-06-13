<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\PostTargetStatus;
use App\Models\PostTarget;
use App\Services\Publishing\PublishConnectorRegistry;
use App\Services\Publishing\TokenManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeletePostTarget implements ShouldQueue
{
    use Queueable;

    public function __construct(public PostTarget $target) {}

    public function handle(PublishConnectorRegistry $registry, TokenManager $tokens): void
    {
        $target = $this->target;

        $target->forceFill(['status' => PostTargetStatus::Deleting->value])->save();

        $credentials = $tokens->fresh($target->account()->firstOrFail());
        $registry->for($target->platform)->delete($target, $credentials);

        $target->forceFill(['status' => PostTargetStatus::Deleted->value])->save();
    }
}
