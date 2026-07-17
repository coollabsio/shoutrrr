<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\PostStatus;
use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Post;
use App\Support\PostListItem;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('List scheduled and published posts for a calendar month (YYYY-MM), padded to a 6-week grid.')]
class GetCalendarTool extends WorkspaceTool
{
    public function handle(Request $request): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        $validated = $request->validate([
            'month' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ], [
            'month.regex' => 'Provide the month as YYYY-MM, for example 2026-06.',
        ]);

        $anchor = CarbonImmutable::createFromFormat('Y-m-d', "{$validated['month']}-01")->startOfMonth();
        $start = $anchor->startOfWeek(CarbonImmutable::SUNDAY);
        $end = $start->addDays(41)->endOfDay();

        $posts = Post::query()
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
            ->map(fn (Post $post): array => PostListItem::make($post));

        return Response::text(json_encode(['month' => $validated['month'], 'posts' => $posts], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'month' => $schema->string()
                ->description('Calendar month as YYYY-MM, e.g. 2026-06.')
                ->required(),
        ];
    }
}
