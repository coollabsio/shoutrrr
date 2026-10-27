<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Exceptions\AgentIntentRejectedException;
use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Post;
use App\Models\User;
use App\Services\Posts\AgentIntentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('schedule_at')]
#[Description('Create a post and schedule it for a future ISO-8601 time. Idempotent on idempotency_key: the same key and payload returns the original post and does not schedule again.')]
class ScheduleAtTool extends WorkspaceTool
{
    public function handle(Request $request, AgentIntentService $intents): Response
    {
        $workspaceId = $this->bindWorkspace($request);
        if ($workspaceId === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        if ($denied = $this->authorize($request, 'create', Post::class)) {
            return $denied;
        }

        $validated = $request->validate([
            'text' => ['required', 'string'],
            'idempotency_key' => ['required', 'string', 'max:255'],
            'scheduled_at' => ['required', 'date'],
            'destination' => ['required', 'array'],
            'destination.kind' => ['required', Rule::in(['all', 'set', 'account'])],
            'destination.id' => ['nullable', 'string', 'required_if:destination.kind,set,account'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $result = $intents->scheduleAt(
                $user,
                $workspaceId,
                $validated['idempotency_key'],
                $validated['text'],
                $validated['destination'],
                $validated['scheduled_at'],
            );
        } catch (AgentIntentRejectedException $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::text(json_encode([
            'idempotent_replay' => $result['idempotent_replay'],
            'post' => $result['post'],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()->description('The post body.')->required(),
            'idempotency_key' => $schema->string()->description('Stable key for this request. Replays return the original post.')->required(),
            'scheduled_at' => $schema->string()->description('Future ISO-8601 time.')->required(),
            'destination' => $schema->object([
                'kind' => $schema->string()->enum(['all', 'set', 'account'])->required(),
                'id' => $schema->string()->description('Account set id (kind=set) or connected account id (kind=account).'),
            ])->description('Where to post.')->required(),
        ];
    }
}
