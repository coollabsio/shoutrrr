<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AccountMetricFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property string $id
 * @property string $connected_account_id
 * @property CarbonImmutable $captured_at
 * @property int $followers
 * @property int|null $following
 * @property int|null $posts_count
 * @property array<string, mixed>|null $raw
 */
#[Fillable(['connected_account_id', 'captured_at', 'followers', 'following', 'posts_count', 'raw'])]
class AccountMetric extends Model
{
    /** @use HasFactory<AccountMetricFactory> */
    use HasFactory, HasUuids;

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'captured_at' => 'immutable_datetime',
            'followers' => 'integer',
            'following' => 'integer',
            'posts_count' => 'integer',
            'raw' => 'array',
        ];
    }

    /** @return BelongsTo<ConnectedAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ConnectedAccount::class, 'connected_account_id');
    }
}
