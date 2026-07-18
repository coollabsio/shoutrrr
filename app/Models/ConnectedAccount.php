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
use Illuminate\Database\Eloquent\Builder;
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
 * @property CarbonImmutable|null $disabled_at
 * @property array<string, mixed>|null $capabilities
 * @property CarbonImmutable|null $token_expires_at
 * @property CarbonImmutable|null $last_refreshed_at
 * @property CarbonImmutable|null $refresh_failed_at
 * @property string|null $refresh_failure_reason
 * @property CarbonImmutable|null $metrics_captured_at
 * @property MetricsStatus|null $metrics_status
 * @property CarbonImmutable|null $engagement_rate_limited_until
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
    'disabled_at',
    'capabilities',
    'token_expires_at',
    'last_refreshed_at',
    'refresh_failed_at',
    'refresh_failure_reason',
    'metrics_captured_at',
    'metrics_status',
    'engagement_rate_limited_until',
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
            'disabled_at' => 'immutable_datetime',
            'capabilities' => 'array',
            'token_expires_at' => 'immutable_datetime',
            'last_refreshed_at' => 'immutable_datetime',
            'refresh_failed_at' => 'immutable_datetime',
            'metrics_captured_at' => 'immutable_datetime',
            'metrics_status' => MetricsStatus::class,
            'engagement_rate_limited_until' => 'immutable_datetime',
        ];
    }

    public function maxTextLength(): int
    {
        return (int) ($this->capabilities['max_text_length'] ?? $this->platform->maxLength());
    }

    public function hasXPremium(): bool
    {
        return $this->platform === Platform::X
            && (bool) ($this->capabilities['x_premium'] ?? false);
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /**
     * Whether this account's credentials can read replies for the engagement
     * inbox. Only LinkedIn is capability-gated: reading member comments needs the
     * restricted `r_member_social_feed` scope, recorded at connect. Accounts
     * connected before that grant (null capability) — or whose grant lacked it —
     * are skipped so we don't hammer LinkedIn with calls that 403.
     */
    public function canFetchEngagement(): bool
    {
        if ($this->platform !== Platform::LinkedIn) {
            return true;
        }

        return (bool) ($this->capabilities['linkedin_engagement'] ?? false);
    }

    /**
     * @param  Builder<ConnectedAccount>  $query
     */
    public function scopeEnabled(Builder $query): void
    {
        $query->whereNull('disabled_at');
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
