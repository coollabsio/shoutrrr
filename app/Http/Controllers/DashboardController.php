<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Models\AccountSet;
use App\Models\ConnectedAccount;
use App\Models\Post;
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
        $accounts = ConnectedAccount::query()
            ->get()
            ->map(fn (ConnectedAccount $account): array => [
                'id' => $account->id,
                'platform' => $account->platform->value,
                'handle' => $account->handle,
                'display_name' => $account->display_name,
                'avatar_url' => $account->avatar_url,
            ])->all();

        $sets = AccountSet::query()
            ->with('accounts:id')
            ->get()
            ->map(fn (AccountSet $set): array => [
                'id' => $set->id,
                'name' => $set->name,
                'connected_account_ids' => $set->accounts->pluck('id')->all(),
            ])->all();

        $posts = Post::query()
            ->with(['author:id,name', 'targets'])
            ->latest('updated_at')
            ->limit(25)
            ->get()
            ->map(fn (Post $post): array => [
                'id' => $post->id,
                'base_text' => $post->base_text,
                'status' => $post->status->value,
                'status_label' => $post->status->label(),
                'author' => $post->author?->name,
                'target_count' => $post->targets->count(),
                'updated_at' => $post->updated_at->toIso8601String(),
                'scheduled_at' => $post->scheduled_at?->toIso8601String(),
                'platforms' => $post->targets->pluck('platform')->map(fn (Platform $p): string => $p->value)->unique()->values()->all(),
            ])->all();

        return Inertia::render('dashboard', [
            'accounts' => $accounts,
            'sets' => $sets,
            'limits' => Platform::allLimits(),
            'posts' => $posts,
        ]);
    }
}
