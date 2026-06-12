<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\Context;

class PostPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isMember($user);
    }

    public function view(User $user, Post $post): bool
    {
        return $this->isMember($user) && $this->ownsPost($post);
    }

    public function create(User $user): bool
    {
        return $this->isMember($user);
    }

    public function update(User $user, Post $post): bool
    {
        return $this->isMember($user) && $this->ownsPost($post);
    }

    public function delete(User $user, Post $post): bool
    {
        return $this->isMember($user) && $this->ownsPost($post);
    }

    private function isMember(User $user): bool
    {
        return $user->hasAllPermissions(['workspace.read'], Context::get('workspace_id'));
    }

    private function ownsPost(Post $post): bool
    {
        return $post->workspace_id === Context::get('workspace_id');
    }
}
