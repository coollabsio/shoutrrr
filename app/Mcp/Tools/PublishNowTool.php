<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Dto\Post\IntentContent;
use App\Exceptions\AgentIntentRejectedException;
use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Post;
use App\Models\User;
use App\Services\Posts\AgentIntentService;
use App\Support\PostComposerInput;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('publish_now')]
#[Description('Create a post and publish it immediately. Supports threads (segments), media_ids, and mentions. Idempotent on idempotency_key: the same key and payload returns the original post and does not publish again. Irreversible and outward-facing. Requires confirm=true.')]
class PublishNowTool extends WorkspaceTool
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

        if ($unconfirmed = $this->requireConfirmation($request, 'This will create a post and publicly publish it to its connected accounts now.')) {
            return $unconfirmed;
        }

        $validated = $request->validate([
            'text' => ['required_without:segments', 'nullable', 'string'],
            ...PostComposerInput::contentRules(),
            'idempotency_key' => ['required', 'string', 'max:255'],
            'confirm' => ['boolean'],
            'destination' => ['required', 'array'],
            'destination.kind' => ['required', Rule::in(['all', 'set', 'account'])],
            'destination.id' => ['nullable', 'string', 'required_if:destination.kind,set,account'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $result = $intents->publishNow(
                $user,
                $workspaceId,
                $validated['idempotency_key'],
                IntentContent::fromValidated($validated),
                $validated['destination'],
            );
        } catch (AgentIntentRejectedException $exception) {
            return $this->rejected($exception);
        }

        return Response::text(json_encode([
            'status' => $result['idempotent_replay'] ? 'replayed' : 'queued',
            'idempotent_replay' => $result['idempotent_replay'],
            'message' => $result['idempotent_replay']
                ? 'This idempotency key already published a post. Returning the original result.'
                : 'Publishing started. Poll get_post for per-target status.',
            'post' => $result['post'],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()->description('The post body. Required unless segments is given; ignored when segments is non-empty.'),
            ...PostComposerInput::contentSchema($schema),
            'idempotency_key' => $schema->string()->description('Stable key for this request. Replays return the original post.')->required(),
            'confirm' => $schema->boolean()->description('Must be true to actually publish.'),
            'destination' => $schema->object([
                'kind' => $schema->string()->enum(['all', 'set', 'account'])->required(),
                'id' => $schema->string()->description('Account set id (kind=set) or connected account id (kind=account).'),
            ])->description('Where to post.')->required(),
        ];
    }

    private function rejected(AgentIntentRejectedException $exception): Response
    {
        $message = $exception->getMessage();

        if ($exception->payload !== []) {
            $message .= ' '.json_encode($exception->payload, JSON_THROW_ON_ERROR);
        }

        return Response::error($message);
    }
}
