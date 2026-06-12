<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasWorkspaceScope;
use Database\Factories\AccountSetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Override;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $name
 * @property bool $is_default
 */
#[Fillable([
    'workspace_id',
    'name',
    'is_default',
])]
class AccountSet extends Model
{
    /** @use HasFactory<AccountSetFactory> */
    use HasFactory, HasUuids, HasWorkspaceScope;

    #[Override]
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsToMany<ConnectedAccount, $this, AccountSetMember>
     */
    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(
            ConnectedAccount::class,
            'account_set_members',
            'account_set_id',
            'connected_account_id',
        )->using(AccountSetMember::class)->withTimestamps();
    }
}
