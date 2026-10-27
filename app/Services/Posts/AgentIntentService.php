<?php

declare(strict_types=1);

namespace App\Services\Posts;

use App\Dto\Post\DraftData;
use App\Dto\Post\IntentContent;
use App\Enums\AgentIntentAction;
use App\Enums\PostOrigin;
use App\Enums\PostStatus;
use App\Exceptions\AgentIntentRejectedException;
use App\Models\AgentIntent;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\WorkspaceSubscriptionGate;
use App\Services\Publishing\PublishDispatcher;
use App\Support\PostView;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Create-and-publish or create-and-schedule in one idempotent step.
 * Classic composer flows keep calling DraftService directly.
 */
class AgentIntentService
{
    public function __construct(
        private readonly DraftService $drafts,
        private readonly PublishDispatcher $dispatcher,
        private readonly PublishPrecheck $precheck,
        private readonly WorkspaceSubscriptionGate $subscriptions,
    ) {}

    /**
     * @param  array{kind: string, id?: string|null}  $destination
     * @return array{post: array<string, mixed>, idempotent_replay: bool}
     */
    public function publishNow(User $user, string $workspaceId, string $idempotencyKey, IntentContent $content, array $destination): array
    {
        $this->assertMemberCanCreate($user);
        $this->assertCanPublish($workspaceId);
        $this->assertMediaInWorkspace($workspaceId, $content->mediaIds);

        $resolved = $this->claim(
            $user,
            $workspaceId,
            $idempotencyKey,
            AgentIntentAction::PublishNow,
            $content,
            $destination,
            null,
            function (Post $post): void {
                $post->loadMissing(['targets.account', 'media']);

                if ($post->targets->isEmpty()) {
                    throw new AgentIntentRejectedException('Select at least one account to publish.');
                }

                $blocked = $this->precheck->blockingTargets($post);
                if ($blocked !== []) {
                    throw new AgentIntentRejectedException(
                        "Some accounts can't be published yet.",
                        422,
                        ['blocked' => $blocked],
                    );
                }

                $post->status = PostStatus::Publishing;
                $post->save();
            },
        );

        if (! $resolved['replay']) {
            $this->dispatcher->dispatchForPost($resolved['post']);
        }

        return $this->present($resolved['post'], $resolved['replay']);
    }

    /**
     * @param  array{kind: string, id?: string|null}  $destination
     * @return array{post: array<string, mixed>, idempotent_replay: bool}
     */
    public function scheduleAt(User $user, string $workspaceId, string $idempotencyKey, IntentContent $content, array $destination, string $scheduledAt): array
    {
        $this->assertMemberCanCreate($user);
        $this->assertCanPublish($workspaceId);
        $this->assertMediaInWorkspace($workspaceId, $content->mediaIds);

        $resolved = $this->claim(
            $user,
            $workspaceId,
            $idempotencyKey,
            AgentIntentAction::ScheduleAt,
            $content,
            $destination,
            $scheduledAt,
            function (Post $post) use ($scheduledAt): void {
                $when = CarbonImmutable::parse($scheduledAt);
                if ($when->lessThanOrEqualTo(CarbonImmutable::now())) {
                    throw new AgentIntentRejectedException('Choose a time in the future — a post cannot be scheduled in the past.');
                }

                $post->scheduled_at = $when;
                $post->status = PostStatus::Scheduled;
                $post->save();
            },
        );

        return $this->present($resolved['post'], $resolved['replay']);
    }

    /**
     * @param  array{kind: string, id?: string|null}  $destination
     * @param  Closure(Post): void  $apply
     * @return array{post: Post, replay: bool}
     */
    private function claim(
        User $user,
        string $workspaceId,
        string $idempotencyKey,
        AgentIntentAction $action,
        IntentContent $content,
        array $destination,
        ?string $scheduledAt,
        Closure $apply,
    ): array {
        $fingerprint = $this->fingerprint($action, $content, $destination, $scheduledAt);

        try {
            return DB::transaction(function () use ($user, $workspaceId, $idempotencyKey, $action, $content, $destination, $fingerprint, $apply): array {
                $existing = AgentIntent::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof AgentIntent) {
                    return $this->replay($existing, $fingerprint);
                }

                $segments = $content->effectiveSegments();
                $post = $this->drafts->createDraft(
                    $workspaceId,
                    $user,
                    $destination,
                    $segments,
                    $content->mentions,
                    data: DraftData::fromArray([
                        'segments' => $segments,
                        'destination' => $destination,
                        'media_ids' => $content->mediaIds,
                    ]),
                );
                $post->origin = PostOrigin::Agent;
                $post->save();

                $apply($post);

                AgentIntent::query()->create([
                    'workspace_id' => $workspaceId,
                    'user_id' => $user->id,
                    'post_id' => $post->id,
                    'idempotency_key' => $idempotencyKey,
                    'action' => $action,
                    'request_hash' => $fingerprint,
                ]);

                return ['post' => $post, 'replay' => false];
            });
        } catch (UniqueConstraintViolationException) {
            $existing = AgentIntent::query()
                ->where('workspace_id', $workspaceId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if (! $existing instanceof AgentIntent) {
                throw new AgentIntentRejectedException('Could not record this intent. Retry with the same idempotency key.', 409);
            }

            return $this->replay($existing, $fingerprint);
        }
    }

    /**
     * @return array{post: Post, replay: bool}
     */
    private function replay(AgentIntent $existing, string $fingerprint): array
    {
        if (! hash_equals($existing->request_hash, $fingerprint)) {
            throw new AgentIntentRejectedException('This idempotency key was already used for a different request.', 409);
        }

        $post = $existing->post;
        if (! $post instanceof Post) {
            throw new AgentIntentRejectedException('The original post for this idempotency key no longer exists.', 409);
        }

        return ['post' => $post->load(['targets.account', 'media']), 'replay' => true];
    }

    /**
     * @return array{post: array<string, mixed>, idempotent_replay: bool}
     */
    private function present(Post $post, bool $replay): array
    {
        $fresh = $post->fresh(['targets.account', 'media']);

        return [
            'post' => PostView::make($fresh ?? $post),
            'idempotent_replay' => $replay,
        ];
    }

    private function assertMemberCanCreate(User $user): void
    {
        if (Gate::forUser($user)->denies('create', Post::class)) {
            throw new AgentIntentRejectedException('You do not have permission to perform this action in this workspace.', 403);
        }
    }

    /**
     * DraftService attaches only media it finds in the workspace and skips the
     * rest, which would publish a post silently missing an image. Reject instead.
     *
     * @param  list<string>  $mediaIds
     */
    private function assertMediaInWorkspace(string $workspaceId, array $mediaIds): void
    {
        if ($mediaIds === []) {
            return;
        }

        $found = PostMedia::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereIn('id', $mediaIds)
            ->pluck('id')
            ->all();
        $missing = array_values(array_diff($mediaIds, $found));

        if ($missing !== []) {
            throw new AgentIntentRejectedException('No media with these ids exists in this workspace.', 422, ['missing_media_ids' => $missing]);
        }
    }

    private function assertCanPublish(string $workspaceId): void
    {
        $workspace = Workspace::query()->whereKey($workspaceId)->first();

        if (! $workspace instanceof Workspace || ! $this->subscriptions->canPublish($workspace)) {
            throw new AgentIntentRejectedException('This workspace requires an active subscription before publishing.', 402);
        }
    }

    /**
     * @param  array{kind: string, id?: string|null}  $destination
     */
    private function fingerprint(AgentIntentAction $action, IntentContent $content, array $destination, ?string $scheduledAt): string
    {
        $canonical = [
            'action' => $action->value,
            'destination' => [
                'id' => $destination['id'] ?? null,
                'kind' => $destination['kind'],
            ],
            'scheduled_at' => $scheduledAt === null
                ? null
                : Date::parse($scheduledAt)->utc()->toIso8601String(),
            'text' => $content->text,
            ...$content->fingerprintFields(),
        ];

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }
}
