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
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Str;
use Override;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string|null $account_set_id
 * @property string $author_id
 * @property string $base_text
 * @property list<string> $segments
 * @property list<array{id: string, label: string, handles: array<string, string>}>|null $mentions
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
    'segments',
    'mentions',
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
            'mentions' => 'array',
            'segments' => 'array',
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

    /**
     * @return HasMany<PostShare, $this>
     */
    public function shares(): HasMany
    {
        return $this->hasMany(PostShare::class);
    }

    /**
     * Every reply across all of this post's per-platform targets — used to
     * count and filter engagement by post.
     *
     * @return HasManyThrough<PostTargetReply, PostTarget, $this>
     */
    public function replies(): HasManyThrough
    {
        return $this->hasManyThrough(PostTargetReply::class, PostTarget::class);
    }

    /**
     * A short, single-line snippet of the post text for notifications and
     * lists. Collapses runs of whitespace and falls back to a placeholder for
     * media-only posts that carry no text.
     */
    public function excerpt(int $limit = 80): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $this->base_text));

        return $text === '' ? 'Media post' : Str::limit($text, $limit);
    }
}
