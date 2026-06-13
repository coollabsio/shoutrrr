<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Services\Publishing\PublishDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

class DispatchDuePosts extends Command
{
    protected $signature = 'posts:dispatch-due';

    protected $description = 'Claim due scheduled posts and dispatch their per-target publish jobs.';

    public function handle(PublishDispatcher $dispatcher): int
    {
        $candidateIds = Post::query()
            ->withoutGlobalScopes()
            ->where('status', PostStatus::Scheduled->value)
            ->where('scheduled_at', '<=', Date::now())
            ->pluck('id')
            ->all();

        if ($candidateIds === []) {
            return self::SUCCESS;
        }

        foreach ($candidateIds as $id) {
            // Per-row atomic claim: the conditional update flips exactly the rows still
            // `scheduled`, so it returns 1 only for the run that actually won the claim.
            // We fan out ONLY for those, never picking up another run's already-claimed rows.
            $claimed = Post::query()
                ->withoutGlobalScopes()
                ->where('id', $id)
                ->where('status', PostStatus::Scheduled->value)
                ->update(['status' => PostStatus::Publishing->value]);

            if ($claimed !== 1) {
                continue;
            }

            $post = Post::query()->withoutGlobalScopes()->where('id', $id)->firstOrFail();
            $dispatcher->dispatchForPost($post);
        }

        return self::SUCCESS;
    }
}
