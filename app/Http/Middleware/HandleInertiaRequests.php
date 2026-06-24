<?php

namespace App\Http\Middleware;

use App\Enums\Platform;
use App\Enums\SocialProvider;
use App\Models\AccountSet;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Support\InstanceSettings;
use App\Support\Notifications\NotificationPresenter;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Override;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    #[Override]
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    #[Override]
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'workspaces' => $this->workspacesData($request->user()),
            'shell' => $this->shellData($request->user()),
            'socialite' => [
                'providers' => SocialProvider::enabledProviders(),
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
            'notifications' => $this->notificationsData($request->user()),
            'features' => [
                'analytics' => (bool) config('metrics.enabled'),
            ],
            'instance' => [
                'isOwner' => $request->user()?->isInstanceOwner() ?? false,
            ],
        ];
    }

    /**
     * Shell data needed by the sidebar, composer, and command palette on nearly
     * every page. Kept lightweight so it is cheap to resolve per request.
     *
     * @return array{accounts: array<int, array<string, mixed>>, sets: array<int, array<string, mixed>>, limits: mixed}
     */
    private function shellData(?User $user): array
    {
        if (! $user || ! $user->current_workspace_id) {
            return ['accounts' => [], 'sets' => [], 'limits' => Platform::allLimits()];
        }

        // Scope explicitly to the current workspace. The HasWorkspaceScope global
        // scope also covers this, but only once WorkspaceMiddleware has populated
        // the context — being explicit keeps shell data correct regardless of
        // middleware ordering and prevents cross-workspace leakage.
        $workspaceId = $user->current_workspace_id;
        $defaultAccountId = $user->currentWorkspace()->value('default_connected_account_id');

        $accounts = ConnectedAccount::query()
            ->where('workspace_id', $workspaceId)
            ->get()
            ->sortByDesc(fn (ConnectedAccount $account): bool => $account->id === $defaultAccountId)
            ->map(fn (ConnectedAccount $account): array => [
                'id' => $account->id,
                'platform' => $account->platform->value,
                'handle' => $account->handle,
                'display_name' => $account->display_name,
                'avatar_url' => $account->avatar_url,
                'max_text_length' => $account->maxTextLength(),
                'x_premium' => $account->hasXPremium(),
            ])->values()->all();

        $sets = AccountSet::query()
            ->where('workspace_id', $workspaceId)
            ->with('accounts:id')
            ->get()
            ->map(fn (AccountSet $set): array => [
                'id' => $set->id,
                'name' => $set->name,
                'connected_account_ids' => $set->accounts->pluck('id')->all(),
            ])->values()->all();

        return [
            'accounts' => $accounts,
            'sets' => $sets,
            'limits' => Platform::allLimits(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function workspacesData(?User $user): array
    {
        $enabled = (bool) config('kit.workspaces.enabled');
        $canCreate = $enabled && app(InstanceSettings::class)->workspaceCreationEnabled();

        if (! $user) {
            return [
                'enabled' => $enabled,
                'all' => [],
                'current' => null,
                'canCreateWorkspaces' => $canCreate,
            ];
        }

        $memberships = $user->workspaceMemberships()->with('workspace.postingSchedule')->get();

        $all = $memberships->map(fn (WorkspaceMembership $m) => [
            'id' => $m->workspace->id,
            'name' => $m->workspace->name,
            'role' => $m->role->value,
            'logo' => $m->workspace->logo,
        ])->values()->all();

        $current = null;
        if ($user->current_workspace_id) {
            $membership = $memberships->firstWhere('workspace_id', $user->current_workspace_id);

            if ($membership) {
                $current = [
                    'id' => $membership->workspace->id,
                    'name' => $membership->workspace->name,
                    'role' => $membership->role->value,
                    'logo' => $membership->workspace->logo,
                    'permissions' => $membership->permissions,
                    'timezone' => $membership->workspace->postingSchedule->timezone ?? 'UTC',
                ];
            }
        }

        return [
            'enabled' => $enabled,
            'all' => $all,
            'current' => $current,
            'canCreateWorkspaces' => $canCreate,
        ];
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, unreadCount: int}
     */
    private function notificationsData(?User $user): array
    {
        if ($user === null) {
            return ['items' => [], 'unreadCount' => 0];
        }

        return NotificationPresenter::collection($user, $user->current_workspace_id);
    }
}
