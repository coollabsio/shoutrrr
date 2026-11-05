<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\PostStatus;
use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Post;
use App\Support\PostView;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('schedule_post')]
#[Description('Schedule a post for a specific future time (ISO-8601). Omit scheduled_at or pass null to un-schedule back to draft.')]
class SchedulePostTool extends WorkspaceTool
{
    public function handle(Request $request): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        $validated = $request->validate([
            'post_id' => ['required', 'string'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ], [
            'scheduled_at.after' => 'Choose a time in the future — a post cannot be scheduled in the past.',
        ]);

        $post = Post::query()->whereKey($validated['post_id'])->first();
        if ($post === null) {
            return Response::error('No post with that id exists in this workspace.');
        }

        if (($validated['scheduled_at'] ?? null) !== null) {
            $post->scheduled_at = $validated['scheduled_at'];
            $post->status = PostStatus::Scheduled;
        } else {
            $post->scheduled_at = null;
            $post->status = PostStatus::Draft;
        }
        $post->save();

        return Response::text(json_encode(PostView::make($post->fresh(['targets.account', 'media'])), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->description('Id of the post to schedule.')->required(),
            'scheduled_at' => $schema->string()->description('Future ISO-8601 time, or null to un-schedule.'),
        ];
    }
}
