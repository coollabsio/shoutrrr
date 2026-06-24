<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\Workspace;
use App\Models\WorkspaceMention;
use App\Support\Onboarding\OnboardingPresenter;
use App\Support\PostListItem;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * The compose-first home: the inline composer plus a recent-posts feed.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $workspace = $user?->currentWorkspace;

        return Inertia::render('dashboard', [
            'onboarding' => $workspace instanceof Workspace
                ? OnboardingPresenter::make($workspace, $user)
                : null,
            'savedMentions' => $user?->current_workspace_id
                ? WorkspaceMention::withoutGlobalScopes()
                    ->where('workspace_id', $user->current_workspace_id)
                    ->orderBy('name')
                    ->get()
                    ->map(fn (WorkspaceMention $mention): array => WorkspaceMentionController::view($mention))
                    ->all()
                : [],
            'posts' => Inertia::defer(fn (): array => Post::query()
                ->with(['author:id,name', 'targets'])
                ->latest('updated_at')
                ->limit(25)
                ->get()
                ->map(fn (Post $post): array => PostListItem::make($post))
                ->all()),
        ]);
    }
}
