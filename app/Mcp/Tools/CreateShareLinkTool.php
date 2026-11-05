<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Post;
use App\Models\User;
use App\Services\Posts\ShareService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_share_link')]
#[Description('Create a share link for a post. Returns a public URL that can be shared without authentication.')]
class CreateShareLinkTool extends WorkspaceTool
{
    public function handle(Request $request, ShareService $shares): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        $validated = $request->validate([
            'post_id' => ['required', 'string'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $post = Post::query()->whereKey($validated['post_id'])->first();
        if ($post === null) {
            return Response::error('No post with that id exists in this workspace.');
        }

        /** @var User $user */
        $user = $request->user();

        $expiresAt = ($validated['expires_at'] ?? null) !== null
            ? CarbonImmutable::parse($validated['expires_at'])
            : null;

        [$share, $token] = $shares->mint($post, $user, $expiresAt);

        return Response::text(json_encode([
            'id' => $share->id,
            'url' => $shares->url($token),
            'expires_at' => $share->expires_at?->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->description('Id of the post to share.')->required(),
            'expires_at' => $schema->string()->description('Optional ISO-8601 expiry date for the share link.'),
        ];
    }
}
