<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engagement;

use App\Enums\ReplyStatus;
use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Models\PostTargetReply;
use App\Support\ReplyListItem;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EngagementController extends Controller
{
    public function index(Request $request): Response
    {
        $account = $request->string('account')->toString();
        $platform = $request->string('platform')->toString();
        $target = $request->string('target')->toString();
        $unread = $request->boolean('unread');

        $apply = fn ($query) => $query
            ->where('is_ours', false)
            ->where('status', '!=', ReplyStatus::Archived->value)
            ->when($unread, fn ($q) => $q->whereNull('read_at'))
            ->when($platform !== '', fn ($q) => $q->where('platform', $platform))
            ->when($target !== '', fn ($q) => $q->where('post_target_id', $target))
            ->when($account !== '', fn ($q) => $q->whereHas('target',
                fn ($t) => $t->where('connected_account_id', $account)));

        $accounts = ConnectedAccount::query()->get(['id', 'handle', 'platform'])
            ->map(fn (ConnectedAccount $a): array => [
                'id' => $a->id,
                'handle' => $a->handle,
                'platform' => $a->platform->value,
            ])->all();

        return Inertia::render('engagement/index', [
            'replies' => Inertia::scroll(fn () => $apply(
                PostTargetReply::query()->with(['target.post', 'target.account'])
            )
                ->orderByDesc('remote_created_at')
                ->cursorPaginate(25)
                ->withQueryString()
                ->through(fn (PostTargetReply $reply): array => ReplyListItem::make($reply)))->defer(),
            'filters' => [
                'account' => $account,
                'platform' => $platform,
                'target' => $target,
                'unread' => $unread,
            ],
            'facets' => ['accounts' => $accounts],
        ]);
    }
}
