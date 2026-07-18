<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\WorkspaceMentionController;
use App\Models\AccountSet;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\WorkspaceMention;
use App\Support\InstanceSettings;
use App\Support\MetricsPresenter;
use App\Support\PostView;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ComposerController extends Controller
{
    public function show(Request $request, Post $post): Response
    {
        $request->user()->can('viewAny', Post::class) ?: abort(403);
        $defaultAccountId = $request->user()->currentWorkspace()->value('default_connected_account_id');
        $settings = app(InstanceSettings::class);

        $accounts = ConnectedAccount::query()
            ->enabled()
            ->get()
            ->filter(fn (ConnectedAccount $account): bool => $settings->platformAvailable($account->platform))
            ->sortByDesc(fn (ConnectedAccount $account): bool => $account->id === $defaultAccountId)
            ->map(fn (ConnectedAccount $account): array => [
                'id' => $account->id,
                'platform' => $account->platform->value,
                'handle' => $account->handle,
                'display_name' => $account->display_name,
                'avatar_url' => $account->avatar_url,
                'status' => $account->status->value,
                'max_text_length' => $account->maxTextLength(),
                'x_premium' => $account->hasXPremium(),
            ])->values()->all();

        $sets = AccountSet::query()
            ->with('accounts:id')
            ->get()
            ->map(fn (AccountSet $set): array => [
                'id' => $set->id,
                'name' => $set->name,
                'connected_account_ids' => $set->accounts->pluck('id')->all(),
            ])->all();

        return Inertia::render('compose/index', [
            'post' => PostView::make($post->load(['targets.account', 'media'])),
            'accounts' => $accounts,
            'sets' => $sets,
            'limits' => Platform::allLimits(),
            'savedMentions' => WorkspaceMention::withoutGlobalScopes()
                ->where('workspace_id', $request->user()->current_workspace_id)
                ->orderBy('name')
                ->get()
                ->map(fn (WorkspaceMention $mention): array => WorkspaceMentionController::view($mention))
                ->all(),
            'stats' => $settings->metricsEnabled()
                ? Inertia::defer(fn (): ?array => $post->targets()
                    ->where('status', PostTargetStatus::Published->value)
                    ->exists()
                    ? MetricsPresenter::forPost($post)
                    : null)
                : null,
        ]);
    }
}
