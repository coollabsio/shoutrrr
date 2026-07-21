<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\CannotDeleteInitialWorkspace;
use App\Support\FileStorage;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\UniqueConstraintViolationException;
use Laravel\Cashier\Billable;
use Laravel\Cashier\Subscription;
use Override;
use Stripe\Subscription as StripeSubscription;

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

    #[Override]
    protected static function booted(): void
    {
        // The very first workspace created on the instance is flagged as the free
        // "initial" workspace. The billing gate exempts it from subscriptions, so
        // it must be a stable, persisted flag — and it can never be deleted on a
        // cloud instance, otherwise the exemption would silently move to the
        // next-oldest workspace.
        //
        // This read is optimistic: two concurrent first-ever creations can both
        // observe an empty table. The `workspaces_single_initial` partial unique
        // index is what actually enforces the invariant, and `performInsert()`
        // demotes whichever insert loses the race.
        static::creating(function (Workspace $workspace): void {
            $workspace->is_initial ??= ! static::query()->exists();
        });

        static::deleting(function (Workspace $workspace): void {
            if ($workspace->is_initial && (bool) config('subscriptions.enabled')) {
                throw new CannotDeleteInitialWorkspace;
            }

            $workspace->cancelLiveSubscriptions();
        });
    }

    /**
     * Insert the workspace, conceding the initial flag if another workspace
     * claimed it first.
     *
     * The `creating` hook decides `is_initial` from a read that concurrent
     * creations can both win, so the insert is what settles the race: the
     * partial unique index rejects the loser, and we retry it as a normal paid
     * workspace. Every caller creates workspaces inside a transaction, and a
     * constraint violation aborts the whole transaction on PostgreSQL, so the
     * first attempt runs in a savepoint — the same guard Eloquent's own
     * `createOrFirst()` uses.
     */
    #[Override]
    protected function performInsert(EloquentBuilder $query): bool
    {
        try {
            return $query->withSavepointIfNeeded(fn (): bool => parent::performInsert($query));
        } catch (UniqueConstraintViolationException $e) {
            if (! $this->is_initial) {
                throw $e;
            }

            $this->is_initial = false;

            return parent::performInsert($query);
        }
    }

    /**
     * Cancel every Stripe subscription that can still bill this workspace.
     *
     * The `subscriptions` foreign key cascades on delete, so the row holding the
     * `stripe_id` disappears along with the workspace. Cancelling first keeps Stripe
     * in sync; a Stripe failure bubbles up and aborts the delete, which beats
     * orphaning a subscription nothing can reach afterwards.
     */
    public function cancelLiveSubscriptions(): void
    {
        $this->subscriptions()
            ->whereNotIn('stripe_status', [
                StripeSubscription::STATUS_CANCELED,
                StripeSubscription::STATUS_INCOMPLETE_EXPIRED,
            ])
            ->each(function (Subscription $subscription): void {
                $subscription->cancelNow();
            });
    }

    /**
     * Narrows Cashier's untyped `subscriptions()` relation to the subscription model.
     *
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, $this->getForeignKey())
            ->orderBy('created_at', 'desc');
    }

    public function getLogoAttribute(?string $value): string
    {
        if ($value) {
            if (str_starts_with($value, 'http') || str_starts_with($value, '/')) {
                return $value;
            }

            return FileStorage::url($value, FileStorage::publicImageDiskName());
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
    #[Override]
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

    /**
     * @return HasMany<UsagePeriodCounter, $this>
     */
    public function usagePeriodCounters(): HasMany
    {
        return $this->hasMany(UsagePeriodCounter::class);
    }
}
