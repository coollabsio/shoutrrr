<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasWorkspaceScope;
use App\Enums\Platform;
use App\Enums\ReplyStatus;
use App\Enums\SendStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PostTargetReplyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $post_target_id
 * @property Platform $platform
 * @property string $remote_reply_id
 * @property string|null $remote_cid
 * @property string|null $parent_remote_id
 * @property string $author_handle
 * @property string|null $author_name
 * @property string|null $author_avatar_url
 * @property string $text
 * @property CarbonImmutable $remote_created_at
 * @property CarbonImmutable|null $read_at
 * @property ReplyStatus $status
 * @property string|null $our_reply_remote_id
 * @property CarbonImmutable|null $liked_at
 * @property string|null $like_remote_id
 * @property bool $is_ours
 * @property SendStatus|null $send_status
 * @property CarbonImmutable $fetched_at
 */
#[Fillable([
    'workspace_id',
    'post_target_id',
    'platform',
    'remote_reply_id',
    'remote_cid',
    'parent_remote_id',
    'author_handle',
    'author_name',
    'author_avatar_url',
    'text',
    'remote_created_at',
    'read_at',
    'status',
    'our_reply_remote_id',
    'liked_at',
    'like_remote_id',
    'is_ours',
    'send_status',
    'fetched_at',
])]
class PostTargetReply extends Model
{
    /** @use HasFactory<PostTargetReplyFactory> */
    use HasFactory, HasUuids;

    use HasWorkspaceScope;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'status' => ReplyStatus::class,
            'is_ours' => 'boolean',
            'send_status' => SendStatus::class,
            'liked_at' => 'immutable_datetime',
            'remote_created_at' => 'immutable_datetime',
            'read_at' => 'immutable_datetime',
            'fetched_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<PostTarget, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(PostTarget::class, 'post_target_id');
    }
}
