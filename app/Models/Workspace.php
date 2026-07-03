<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;
use Laravel\Cashier\Billable;

#[Fillable([
    'name',
    'slug',
    'logo',
    'timezone',
    'owner_id',
    'default_connected_account_id',
])]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use Billable, HasFactory, HasUuids;

    #[\Override]
    protected static function booted(): void
    {
        // The very first workspace created on the instance is flagged as the free
        // "initial" workspace. The billing gate exempts it from subscriptions, so
        // it must be a stable, persisted flag — and it can never be deleted on a
        // cloud instance, otherwise the exemption would silently move to the
        // next-oldest workspace.
        static::creating(function (Workspace $workspace): void {
            $workspace->is_initial ??= ! static::query()->exists();
        });

        static::deleting(function (Workspace $workspace): ?bool {
            if ($workspace->is_initial && (bool) config('subscriptions.enabled')) {
                return false;
            }

            return null;
        });
    }

    public function getLogoAttribute(?string $value): string
    {
        if ($value) {
            if (str_starts_with($value, 'http') || str_starts_with($value, '/')) {
                return $value;
            }

            return Storage::disk('public')->url($value);
        }

        return "https://api.dicebear.com/9.x/glass/svg?seed={$this->attributes['id']}";
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function stripeEmail(): ?string
    {
        return $this->owner()->value('email');
    }

    /**
     * @return BelongsTo<ConnectedAccount, $this>
     */
    public function defaultConnectedAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectedAccount::class, 'default_connected_account_id');
    }

    /**
     * @return HasMany<WorkspaceMembership, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class);
    }

    /**
     * @return HasMany<WorkspaceInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(WorkspaceInvitation::class);
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'is_initial' => 'boolean',
            'onboarding_welcomed_at' => 'datetime',
            'onboarding_dismissed_at' => 'datetime',
            'onboarding_progress' => 'array',
        ];
    }

    /**
     * @return HasOne<PostingSchedule, $this>
     */
    public function postingSchedule(): HasOne
    {
        return $this->hasOne(PostingSchedule::class);
    }

    /**
     * @return HasMany<Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * @return HasMany<WorkspaceMention, $this>
     */
    public function mentions(): HasMany
    {
        return $this->hasMany(WorkspaceMention::class);
    }

    /**
     * @return HasMany<ConnectedAccount, $this>
     */
    public function connectedAccounts(): HasMany
    {
        return $this->hasMany(ConnectedAccount::class);
    }
}
