<?php

declare(strict_types=1);

namespace App\Services\ConnectedAccounts;

use App\Models\ConnectedAccount;
use App\Models\Workspace;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Account-level settings writes shared by the REST API and the MCP tools.
 * Callers authorize first; these methods only enforce the data invariants.
 */
class ConnectedAccountSettings
{
    public function setEnabled(ConnectedAccount $account, bool $enabled): void
    {
        DB::transaction(function () use ($account, $enabled): void {
            $account->forceFill(['disabled_at' => $enabled ? null : Date::now()])->save();

            if (! $enabled) {
                $this->clearDefault($account);
            }
        });
    }

    public function makeDefault(ConnectedAccount $account): void
    {
        Workspace::query()->whereKey($account->workspace_id)
            ->update(['default_connected_account_id' => $account->id]);
    }

    /**
     * Merge into capabilities.auto_repost under a row lock so a concurrent
     * capability writer (X tier refresh) cannot clobber the JSON column, and an
     * omitted min_percentile keeps its stored value.
     *
     * @param  float|null  $minPercentile  Fraction between 0 and 1.
     */
    public function setAutoRepost(ConnectedAccount $account, bool $enabled, ?float $minPercentile): void
    {
        $updates = ['enabled' => $account->platform->supportsRepost() && $enabled];

        if ($minPercentile !== null) {
            $updates['min_percentile'] = $minPercentile;
        }

        DB::transaction(function () use ($account, $updates): void {
            $locked = ConnectedAccount::query()->lockForUpdate()->findOrFail($account->id);
            $autoRepost = [...($locked->capabilities['auto_repost'] ?? []), ...$updates];

            $locked->forceFill([
                'capabilities' => [...($locked->capabilities ?? []), 'auto_repost' => $autoRepost],
            ])->save();
        });

        $account->refresh();
    }

    public function disconnect(ConnectedAccount $account): void
    {
        DB::transaction(function () use ($account): void {
            $this->clearDefault($account);
            $account->secret()->delete();
            $account->delete();
        });
    }

    private function clearDefault(ConnectedAccount $account): void
    {
        Workspace::query()->whereKey($account->workspace_id)
            ->where('default_connected_account_id', $account->id)
            ->update(['default_connected_account_id' => null]);
    }
}
