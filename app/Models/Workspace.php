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
    use HasFactory, HasUuids;

    public function getLogoAttribute(?string $value): string
    {
        return $value ?: "https://api.dicebear.com/9.x/glass/svg?seed={$this->attributes['id']}";
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
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
