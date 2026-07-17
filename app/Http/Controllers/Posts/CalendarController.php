<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Enums\PostStatus;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Support\PostListItem;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CalendarController extends Controller
{
    public function redirectToCurrent(): RedirectResponse
    {
        return redirect()->route('calendar.month', ['yyyymm' => now()->format('Y-m')]);
    }

    public function show(Request $request, string $yyyymm): Response
    {
        $request->user()->can('viewAny', Post::class) ?: abort(403);

        $view = $request->string('view')->toString() === 'week' ? 'week' : 'month';
        $anchor = CarbonImmutable::createFromFormat('Y-m-d', "{$yyyymm}-01")->startOfMonth();

        // Pad to a 6-week (42-day) Sunday-first window so adjacent-month edge days
        // that appear in the grid are populated too.
        $start = $anchor->startOfWeek(CarbonImmutable::SUNDAY);
        $end = $start->addDays(41)->endOfDay();

        return Inertia::render('posts/calendar/index', [
            'yyyymm' => $yyyymm,
            'view' => $view,
            'posts' => Inertia::defer(fn (): array => Post::query()
                ->with(['author:id,name', 'targets', 'media'])
                ->whereIn('status', [
                    PostStatus::Scheduled->value, PostStatus::Published->value,
                    PostStatus::Partial->value, PostStatus::Failed->value,
                ])
                ->where(fn ($q) => $q
                    ->whereBetween('scheduled_at', [$start, $end])
                    ->orWhereBetween('published_at', [$start, $end]))
                ->orderByRaw('COALESCE(scheduled_at, published_at) ASC')
                ->get()
                ->map(fn (Post $post): array => PostListItem::make($post))
                ->all()),
        ]);
    }
}
