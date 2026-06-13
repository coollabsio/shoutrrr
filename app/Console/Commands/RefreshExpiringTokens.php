<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Platform;
use App\Exceptions\TokenRefreshException;
use App\Models\ConnectedAccount;
use App\Services\Publishing\TokenManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

class RefreshExpiringTokens extends Command
{
    protected $signature = 'accounts:refresh-tokens';

    protected $description = 'Proactively refresh OAuth tokens nearing expiry.';

    public function handle(TokenManager $tokens): int
    {
        ConnectedAccount::query()
            ->withoutGlobalScopes()
            ->where('platform', '!=', Platform::Bluesky->value)
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<=', Date::now()->addMinutes(30))
            ->each(function (ConnectedAccount $account) use ($tokens): void {
                try {
                    // Force the refresh: these accounts are inside the sweeper window
                    // but typically still outside the just-in-time skew band.
                    $tokens->fresh($account, force: true);
                } catch (TokenRefreshException $e) {
                    // TokenManager already flipped the account to needs-attention;
                    // report the swallowed sweep failure so it stays observable.
                    report($e);
                }
            });

        return self::SUCCESS;
    }
}
