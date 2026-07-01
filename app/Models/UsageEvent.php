<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\UsageEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property string $id
 * @property string|null $workspace_id
 * @property string $category
 * @property string $operation
 * @property string|null $platform
 * @property int $quota_weight
 * @property bool $succeeded
 * @property array<string, mixed>|null $meta
 * @property CarbonImmutable $occurred_at
 */
#[Fillable(['workspace_id', 'category', 'operation', 'platform', 'quota_weight', 'succeeded', 'meta', 'occurred_at'])]
class UsageEvent extends Model
{
    /** @use HasFactory<UsageEventFactory> */
    use HasFactory, HasUuids;

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'quota_weight' => 'integer',
            'succeeded' => 'boolean',
            'meta' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
