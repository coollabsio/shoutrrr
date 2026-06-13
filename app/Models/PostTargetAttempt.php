<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ErrorKind;
use Carbon\CarbonImmutable;
use Database\Factories\PostTargetAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property string $id
 * @property string $post_target_id
 * @property int $attempt_no
 * @property string $status
 * @property ErrorKind|null $error_kind
 * @property string|null $error_message
 * @property int|null $http_status
 * @property string|null $response_excerpt
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 */
#[Fillable([
    'post_target_id',
    'attempt_no',
    'status',
    'error_kind',
    'error_message',
    'http_status',
    'response_excerpt',
    'started_at',
    'finished_at',
])]
class PostTargetAttempt extends Model
{
    /** @use HasFactory<PostTargetAttemptFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'attempt_no' => 'integer',
            'error_kind' => ErrorKind::class,
            'http_status' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
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
