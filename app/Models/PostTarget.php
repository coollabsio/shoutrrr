<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use Database\Factories\PostTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property string $id
 * @property string $post_id
 * @property string $connected_account_id
 * @property Platform $platform
 * @property list<string> $sections
 * @property array{text?: string|null, media_ids?: list<string>}|null $content_override
 * @property bool $auto_split
 * @property PostTargetStatus $status
 */
#[Fillable([
    'post_id',
    'connected_account_id',
    'platform',
    'sections',
    'content_override',
    'auto_split',
    'status',
])]
class PostTarget extends Model
{
    /** @use HasFactory<PostTargetFactory> */
    use HasFactory, HasUuids;

    #[Override]
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'status' => PostTargetStatus::class,
            'sections' => 'array',
            'content_override' => 'array',
            'auto_split' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * @return BelongsTo<ConnectedAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ConnectedAccount::class, 'connected_account_id');
    }
}
