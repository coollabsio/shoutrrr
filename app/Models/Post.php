<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasWorkspaceScope;
use App\Enums\PostStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string|null $account_set_id
 * @property string $author_id
 * @property string $base_text
 * @property PostStatus $status
 * @property CarbonImmutable|null $scheduled_at
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $deleted_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'workspace_id',
    'account_set_id',
    'author_id',
    'base_text',
    'status',
    'scheduled_at',
    'published_at',
    'deleted_at',
])]
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory, HasUuids, HasWorkspaceScope;

    #[Override]
    protected function casts(): array
    {
        return [
            'status' => PostStatus::class,
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
            'deleted_at' => 'datetime',
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
     * @return BelongsTo<AccountSet, $this>
     */
    public function accountSet(): BelongsTo
    {
        return $this->belongsTo(AccountSet::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return HasMany<PostTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(PostTarget::class);
    }

    /**
     * @return HasMany<PostMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(PostMedia::class)->orderBy('position');
    }
}
