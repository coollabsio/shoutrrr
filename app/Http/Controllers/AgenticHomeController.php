<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PostOrigin;
use App\Enums\PostStatus;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\SyncPipeline;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\WorkspaceSubscriptionGate;
use App\Services\ConnectedAccounts\AccountConnectLinks;
use App\Support\PostListItem;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class AgenticHomeController extends Controller
{
    public function __construct(
        private readonly AccountConnectLinks $connectLinks,
        private readonly WorkspaceSubscriptionGate $subscriptions,
    ) {}

    /**
     * Agentic home. Before anything is posted it walks through setup: connect
     * accounts, then turn on sync. After that it shows recent posts. The MCP
     * and REST API setup lives in Settings → Agentic.
     */
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $user->currentWorkspace instanceof Workspace ? $user->currentWorkspace : null;
        $accounts = $workspace === null ? collect() : $this->enabledAccounts($workspace->id);

        return Inertia::render('agentic/home', [
            'platforms' => $user->can('create', ConnectedAccount::class) ? $this->connectablePlatforms() : [],
            'accounts' => $accounts->map(fn (ConnectedAccount $account): array => [
                'id' => $account->id,
                'platform' => $account->platform->value,
                'handle' => $account->handle,
                'display_name' => $account->display_name,
                'avatar_url' => $account->avatar_url,
                'supports_native' => $account->platform->supportsNativeRead(),
            ])->values()->all(),
            'sync' => $workspace === null ? $this->emptySync() : $this->syncState($workspace, $accounts),
            'recent_posts' => $workspace === null ? null : $this->recentPosts($workspace->id),
        ]);
    }

    /**
     * Once sync or an assistant has posted, the home is a feed of recent posts
     * and setup moves out of the way. Null until then.
     *
     * @return list<array<string, mixed>>|null
     */
    private function recentPosts(string $workspaceId): ?array
    {
        $hasPosted = Post::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('origin', [PostOrigin::Sync, PostOrigin::Agent])
            ->exists();

        if (! $hasPosted) {
            return null;
        }

        return array_values(Post::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', '!=', PostStatus::Draft)
            ->with(['targets', 'media', 'author'])
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (Post $post): array => PostListItem::make($post))
            ->all());
    }

    /**
     * @return Collection<int, ConnectedAccount>
     */
    private function enabledAccounts(string $workspaceId): Collection
    {
        return ConnectedAccount::query()
            ->where('workspace_id', $workspaceId)
            ->enabled()
            ->oldest()
            ->get();
    }

    /**
     * @return list<array{platform: string, label: string, method: string, url: string}>
     */
    private function connectablePlatforms(): array
    {
        $platforms = [];

        foreach ($this->connectLinks->listReturningToAgentic() as $link) {
            if ($link['available'] && $link['url'] !== null) {
                $platforms[] = [
                    'platform' => $link['platform'],
                    'label' => $link['label'],
                    'method' => $link['method'],
                    'url' => $link['url'],
                ];
            }
        }

        return $platforms;
    }

    /**
     * @return array{total: int, enabled: int, can_create: bool, can_track: bool, suggestion: null}
     */
    private function emptySync(): array
    {
        return ['total' => 0, 'enabled' => 0, 'can_create' => false, 'can_track' => false, 'suggestion' => null];
    }

    /**
     * @param  Collection<int, ConnectedAccount>  $accounts
     * @return array{total: int, enabled: int, can_create: bool, can_track: bool, suggestion: array{name: string, source_connected_account_id: string, destination_connected_account_ids: list<string>}|null}
     */
    private function syncState(Workspace $workspace, Collection $accounts): array
    {
        $pipelines = SyncPipeline::query()->where('workspace_id', $workspace->id);
        $total = (clone $pipelines)->count();

        return [
            'total' => $total,
            'enabled' => (clone $pipelines)->where('enabled', true)->count(),
            'can_create' => $this->subscriptions->canCreateSyncPipeline($workspace),
            'can_track' => $this->subscriptions->canTrackNativeAccount($workspace),
            'suggestion' => $total === 0 ? $this->suggestPipeline($accounts) : null,
        ];
    }

    /**
     * First sync to offer: the oldest account that can be tracked natively is
     * the source, so posts made directly on that platform sync too. LinkedIn and
     * Discord cannot be tracked, so they are only a source when nothing else is.
     *
     * @param  Collection<int, ConnectedAccount>  $accounts
     * @return array{name: string, source_connected_account_id: string, destination_connected_account_ids: list<string>}|null
     */
    private function suggestPipeline(Collection $accounts): ?array
    {
        if ($accounts->count() < 2) {
            return null;
        }

        $source = $accounts->first(fn (ConnectedAccount $account): bool => $account->platform->supportsNativeRead())
            ?? $accounts->first();

        if (! $source instanceof ConnectedAccount) {
            return null;
        }

        $destinations = array_values($accounts
            ->reject(fn (ConnectedAccount $account): bool => $account->id === $source->id)
            ->take(3)
            ->map(fn (ConnectedAccount $account): string => $account->id)
            ->all());

        return [
            'name' => $source->platform->label().' '.$source->handle.' sync',
            'source_connected_account_id' => $source->id,
            'destination_connected_account_ids' => $destinations,
        ];
    }
}
