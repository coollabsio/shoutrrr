<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ErrorKind;
use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PostTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property string|null $remote_id
 * @property list<string>|null $remote_ids
 * @property ErrorKind|null $error_kind
 * @property string|null $error_message
 * @property int $attempts
 * @property CarbonImmutable|null $next_attempt_at
 * @property string|null $idempotency_key
 * @property CarbonImmutable|null $posted_at
 * @property array<string, mixed>|null $media_upload_state
 * @property int $likes
 * @property int $comments
 * @property int $reposts
 * @property int|null $impressions
 * @property CarbonImmutable|null $metrics_captured_at
 * @property MetricsStatus|null $metrics_status
 * @property int $metrics_unchanged_streak
 * @property CarbonImmutable|null $reply_fetched_at
 * @property int $reply_fetch_empty_streak
 */
#[Fillable([
    'post_id',
    'connected_account_id',
    'platform',
    'sections',
    'content_override',
    'auto_split',
    'status',
    'remote_id',
    'remote_ids',
    'error_kind',
    'error_message',
    'attempts',
    'next_attempt_at',
    'idempotency_key',
    'posted_at',
    'media_upload_state',
    'likes',
    'comments',
    'reposts',
    'impressions',
    'metrics_captured_at',
    'metrics_status',
    'metrics_unchanged_streak',
    'reply_fetched_at',
    'reply_fetch_empty_streak',
])]
class PostTarget extends Model
{
    /** @use HasFactory<PostTargetFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'status' => PostTargetStatus::class,
            'sections' => 'array',
            'content_override' => 'array',
            'auto_split' => 'boolean',
            'remote_ids' => 'array',
            'media_upload_state' => 'array',
            'error_kind' => ErrorKind::class,
            'attempts' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'posted_at' => 'immutable_datetime',
            'likes' => 'integer',
            'comments' => 'integer',
            'reposts' => 'integer',
            'impressions' => 'integer',
            'metrics_captured_at' => 'immutable_datetime',
            'metrics_status' => MetricsStatus::class,
            'metrics_unchanged_streak' => 'integer',
            'reply_fetched_at' => 'immutable_datetime',
            'reply_fetch_empty_streak' => 'integer',
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

    /**
     * @return HasMany<PostTargetAttempt, $this>
     */
    public function attemptLogs(): HasMany
    {
        return $this->hasMany(PostTargetAttempt::class, 'post_target_id');
    }

    /** @return HasMany<PostTargetMetric, $this> */
    public function metrics(): HasMany
    {
        return $this->hasMany(PostTargetMetric::class, 'post_target_id')->orderBy('captured_at');
    }

    /** @return HasMany<PostTargetReply, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(PostTargetReply::class, 'post_target_id');
    }
}
