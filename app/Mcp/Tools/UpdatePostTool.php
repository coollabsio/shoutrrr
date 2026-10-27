<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Dto\Post\DraftData;
use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Post;
use App\Services\Posts\DraftService;
use App\Services\Posts\PostStaleWriteException;
use App\Support\PostComposerInput;
use App\Support\PostView;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Update a draft post: text, destination, per-account overrides, and attached media. Pass expected_updated_at (from get_post) for optimistic concurrency; a mismatch returns a stale-write error.')]
class UpdatePostTool extends WorkspaceTool
{
    public function handle(Request $request, DraftService $drafts): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        $validated = $request->validate([
            'post_id' => ['required', 'string'],
            'base_text' => ['present', 'nullable', 'string'],
            ...PostComposerInput::contentRules(),
            'destination' => ['required', 'array'],
            'destination.kind' => ['required', Rule::in(['all', 'set', 'account'])],
            'destination.id' => ['nullable', 'string', 'required_if:destination.kind,set,account'],
            ...PostComposerInput::targetRules(),
            'auto_repost' => ['sometimes', 'nullable', 'boolean'],
            'expected_updated_at' => ['nullable', 'string'],
        ]);

        PostComposerInput::ensureFormatsSupported($validated['targets'] ?? []);

        $post = Post::query()->whereKey($validated['post_id'])->first();
        if ($post === null) {
            return Response::error('No post with that id exists in this workspace.');
        }

        try {
            $updated = $drafts->updateDraft($post, DraftData::fromArray($validated));
        } catch (PostStaleWriteException $e) {
            return Response::error($e->getMessage().' Re-fetch the post with get_post for the current expected_updated_at, then retry.');
        }

        return Response::text(json_encode(PostView::make($updated->fresh(['targets.account', 'media'])), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->description('Id of the draft to update.')->required(),
            'base_text' => $schema->string()->description('New post body text (ignored when segments is non-empty). Use {{mention:id}} tokens for platform-specific mentions.'),
            ...PostComposerInput::contentSchema($schema),
            'destination' => $schema->object([
                'kind' => $schema->string()->enum(['all', 'set', 'account'])->required(),
                'id' => $schema->string()->description('Account set id (kind=set) or connected account id (kind=account).'),
            ])->description('Where to post.')->required(),
            'targets' => PostComposerInput::targetsSchema($schema),
            'auto_repost' => $schema->boolean()->description('Per-post auto-boost override: true always reshares, false never does, omit to leave the current setting unchanged (null = each account\'s automatic performance gate).'),
            'expected_updated_at' => $schema->string()->description('The post updated_at you last saw, for conflict detection.'),
        ];
    }
}
