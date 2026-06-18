<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasWorkspaceScope;
use App\Enums\ConnectedAccountStatus;
use App\Enums\MetricsStatus;
use App\Enums\Platform;
use Carbon\CarbonImmutable;
use Database\Factories\ConnectedAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Override;

/**
 * @property string $id
 * @property string $workspace_id
 * @property Platform $platform
 * @property string $handle
 * @property string|null $display_name
 * @property string|null $avatar_url
 * @property string $remote_account_id
 * @property string $auth_method
 * @property string|null $connected_by_user_id
 * @property ConnectedAccountStatus $status
 * @property CarbonImmutable|null $token_expires_at
 * @property CarbonImmutable|null $last_refreshed_at
 * @property CarbonImmutable|null $metrics_captured_at
 * @property MetricsStatus|null $metrics_status
 */
#[Fillable([
    'workspace_id',
    'platform',
    'handle',
    'display_name',
    'avatar_url',
    'remote_account_id',
    'auth_method',
    'connected_by_user_id',
    'status',
    'token_expires_at',
    'last_refreshed_at',
    'metrics_captured_at',
    'metrics_status',
])]
class ConnectedAccount extends Model
{
    /** @use HasFactory<ConnectedAccountFactory> */
    use HasFactory, HasUuids, HasWorkspaceScope;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'status' => ConnectedAccountStatus::class,
            'token_expires_at' => 'immutable_datetime',
            'last_refreshed_at' => 'immutable_datetime',
            'metrics_captured_at' => 'immutable_datetime',
            'metrics_status' => MetricsStatus::class,
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
     * @return BelongsTo<User, $this>
     */
    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_user_id');
    }

    /**
     * @return HasOne<ConnectedAccountSecret, $this>
     */
    public function secret(): HasOne
    {
        return $this->hasOne(ConnectedAccountSecret::class, 'connected_account_id');
    }

    /** @return HasMany<AccountMetric, $this> */
    public function metrics(): HasMany
    {
        return $this->hasMany(AccountMetric::class, 'connected_account_id');
    }
}
