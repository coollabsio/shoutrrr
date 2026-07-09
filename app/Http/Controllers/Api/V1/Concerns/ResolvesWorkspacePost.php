<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\Post;

/**
 * Shared post lookup for the API controllers. Kept in one place because it is
 * the workspace-isolation boundary: `Post` carries the HasWorkspaceScope global
 * scope, which ResolveApiWorkspace populates with the caller's bound workspace,
 * so a foreign post id resolves to a 404 rather than leaking across tenants.
 */
trait ResolvesWorkspacePost
{
    protected function findPostOrFail(string $id): Post
    {
        return Post::query()->whereKey($id)->firstOr(
            fn () => abort(404, 'No post with that id exists in this workspace.')
        );
    }
}
