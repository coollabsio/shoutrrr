<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Exceptions\TokenRefreshException;
use App\Exceptions\TransientTokenRefreshException;
use App\Models\ConnectedAccount;
use App\Notifications\AccountReconnectDueNotification;
use App\Services\Publishing\TokenManager;
use App\Support\InstanceSettings;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class RefreshExpiringTokens extends Command
{
    protected $signature = 'accounts:refresh-tokens';

    protected $description = 'Proactively refresh OAuth tokens nearing expiry.';

    /** How long before a TikTok refresh token runs out the sweep starts acting on it. */
    private const int TIKTOK_REFRESH_AHEAD_DAYS = 14;

    /** The sweep refreshes a TikTok account at most once in this many hours. */
    private const int TIKTOK_SWEEP_REFRESH_INTERVAL_HOURS = 24;

    public function handle(TokenManager $tokens): int
    {
        $availablePlatforms = array_keys(array_filter(
            app(InstanceSettings::class)->platformsEnabled(),
        ));

        ConnectedAccount::query()
            ->withoutGlobalScopes()
            ->whereIn('platform', $availablePlatforms)
            // Exclude Bluesky and TikTok: their single-use refresh tokens and short
            // access tokens (Bluesky ~15-30 min, TikTok 2h) would rotate on every
            // sweep. The just-in-time path refreshes them when needed.
            ->whereNotIn('platform', [Platform::Bluesky->value, Platform::TikTok->value])
            ->where('status', ConnectedAccountStatus::Active->value)
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<=', Date::now()->addHours(6))
            ->each(function (ConnectedAccount $account) use ($tokens): void {
                // Not an arrow function: each() stops at the first callback
                // that returns false, and refresh() returns false on a refusal.
                $this->refresh($tokens, $account);
            });

        // The just-in-time path only refreshes a TikTok account when something is
        // published from it, so an idle account would lose its refresh token
        // without warning. TikTok reports when that token runs out, so act on
        // that date: refresh the account ahead of it (TikTok may issue a new
        // token with a fresh lifetime), and when the date stays put, remind the
        // workspace to reconnect. An account whose date is unknown is refreshed
        // so TikTok can report it.
        ConnectedAccount::query()
            ->withoutGlobalScopes()
            ->whereIn('platform', $availablePlatforms)
            ->where('platform', Platform::TikTok->value)
            ->where('status', ConnectedAccountStatus::Active->value)
            ->where(fn (Builder $query) => $query
                ->whereNull('refresh_token_expires_at')
                ->orWhere('refresh_token_expires_at', '<=', Date::now()->addDays(self::TIKTOK_REFRESH_AHEAD_DAYS)))
            ->each(fn (ConnectedAccount $account) => $this->keepTikTokAccountConnected($tokens, $account));

        return self::SUCCESS;
    }

    private function keepTikTokAccountConnected(TokenManager $tokens, ConnectedAccount $account): void
    {
        // Refreshing at most daily keeps a refresh token TikTok will not renew
        // from being refreshed on every sweep until it runs out. A just-in-time
        // refresh in that time has already brought the expiry up to date.
        $lastRefreshedAt = $account->last_refreshed_at ?? $account->created_at;

        if ($lastRefreshedAt?->lte(Date::now()->subHours(self::TIKTOK_SWEEP_REFRESH_INTERVAL_HOURS))
            && ! $this->refresh($tokens, $account)) {
            return;
        }

        $account->refresh();

        if ($account->status !== ConnectedAccountStatus::Active
            || $account->reconnect_reminded_at !== null
            || $account->refresh_token_expires_at === null
            || $account->refresh_token_expires_at->gt(Date::now()->addDays(self::TIKTOK_REFRESH_AHEAD_DAYS))) {
            return;
        }

        $account->forceFill(['reconnect_reminded_at' => Date::now()])->save();

        Notification::send(
            $account->attentionRecipients(),
            new AccountReconnectDueNotification($account, $account->workspace_id),
        );
    }

    /**
     * @return bool false when the provider refused the refresh and the account
     *              now needs reconnecting
     */
    private function refresh(TokenManager $tokens, ConnectedAccount $account): bool
    {
        try {
            // Force the refresh: these accounts are inside the health-check window
            // but typically still outside the just-in-time skew band.
            $tokens->fresh($account, force: true);
        } catch (TransientTokenRefreshException $e) {
            // Provider hiccup (429/5xx/timeout). The account is left Active and the
            // next sweep retries, so this is expected noise — log it, don't report.
            Log::info('Token sweep: transient refresh failure, will retry next run', [
                'account_id' => $account->id,
                'reason' => $e->getMessage(),
            ]);
        } catch (TokenRefreshException $e) {
            // TokenManager already flipped the account to needs-attention;
            // report the swallowed sweep failure so it stays observable.
            report($e);

            return false;
        }

        return true;
    }
}
