<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AccountSet;
use App\Models\User;
use Illuminate\Support\Facades\Context;

class AccountSetPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isMember($user);
    }

    public function create(User $user): bool
    {
        return $this->isMember($user);
    }

    public function update(User $user, AccountSet $set): bool
    {
        return $this->isMember($user) && $set->workspace_id === Context::get('workspace_id');
    }

    public function delete(User $user, AccountSet $set): bool
    {
        return $this->update($user, $set);
    }

    private function isMember(User $user): bool
    {
        return $user->hasAllPermissions(['workspace.read'], Context::get('workspace_id'));
    }
}
