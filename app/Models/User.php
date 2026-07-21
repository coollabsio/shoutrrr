<?php

namespace App\Models;

use App\Casts\NotificationPreferencesCast;
use App\Enums\InstanceRole;
use App\Enums\SocialProvider;
use App\Support\FileStorage;
use App\Support\Notifications\NotificationPreferences;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use Override;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property string|null $avatar_path
 * @property InstanceRole|null $instance_role
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property NotificationPreferences|null $notification_preferences
 */
#[Appends(['avatar'])]
#[Fillable(['name', 'email', 'password', 'current_workspace_id', 'avatar_path', 'notification_preferences', 'instance_role'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, OAuthenticatable, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'notification_preferences' => NotificationPreferencesCast::class,
            'instance_role' => InstanceRole::class,
        ];
    }

    public function getAvatarAttribute(): string
    {
        $path = $this->attributes['avatar_path'] ?? null;

        if ($path) {
            return FileStorage::url($path, FileStorage::publicImageDiskName());
        }

        return "https://api.dicebear.com/9.x/glass/svg?seed={$this->attributes['id']}";
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function currentWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'current_workspace_id');
    }

    /**
     * @return HasMany<WorkspaceMembership, $this>
     */
    public function workspaceMemberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class);
    }

    /**
     * @return HasMany<SocialAccount, $this>
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    #[Override]
    public function hasVerifiedEmail(): bool
    {
        if (! config('auth.email_verification.enabled', false)) {
            return true;
        }

        return ! is_null($this->email_verified_at);
    }

    public function hasPassword(): bool
    {
        return ! is_null($this->password);
    }

    public function hasSocialAccount(SocialProvider $provider): bool
    {
        return $this->socialAccounts()->where('provider', $provider->value)->exists();
    }

    /**
     * Number of distinct ways this user can authenticate. Used to prevent a user
     * from disconnecting their only remaining login method.
     */
    public function loginMethodCount(): int
    {
        return ($this->hasPassword() ? 1 : 0)
            + $this->socialAccounts()->count()
            + $this->passkeys()->count();
    }

    public function getMembershipForWorkspace(?string $workspaceId): ?WorkspaceMembership
    {
        if (! $workspaceId) {
            return null;
        }

        return $this->workspaceMemberships()->where('workspace_id', $workspaceId)->first();
    }

    public function isMemberOfWorkspace(?string $workspaceId): bool
    {
        return $this->getMembershipForWorkspace($workspaceId) !== null;
    }

    public function notificationPreferences(): NotificationPreferences
    {
        return $this->notification_preferences ?? NotificationPreferences::defaults();
    }

    public function isOwnerOfWorkspace(?string $workspaceId): bool
    {
        return $this->getMembershipForWorkspace($workspaceId)?->isOwner() ?? false;
    }

    public function isInstanceOwner(): bool
    {
        return $this->instance_role === InstanceRole::Owner;
    }

    /**
     * @param  array<int, string>  $permissions
     */
    public function hasAllPermissions(array $permissions, ?string $workspaceId): bool
    {
        $membership = $this->getMembershipForWorkspace($workspaceId);

        if (! $membership) {
            return false;
        }

        $granted = $membership->permissions;

        return collect($permissions)->every(fn (string $p) => in_array($p, $granted, true));
    }

    /**
     * @param  array<int, string>  $permissions
     */
    public function hasSomePermissions(array $permissions, ?string $workspaceId): bool
    {
        $membership = $this->getMembershipForWorkspace($workspaceId);

        if (! $membership) {
            return false;
        }

        $granted = $membership->permissions;

        return collect($permissions)->some(fn (string $p) => in_array($p, $granted, true));
    }
}
