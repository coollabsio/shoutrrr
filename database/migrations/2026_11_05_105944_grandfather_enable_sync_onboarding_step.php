<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The "Turn on sync" onboarding step was added after workspaces had already
 * finished the getting-started checklist. Without this, those workspaces would
 * see the card again (and a one-account workspace could never finish it). For
 * every workspace that met all four pre-sync steps, record "enable_sync" in
 * onboarding_progress; the presenter treats that as done.
 *
 * The checks mirror the old presenter: "timezone" in progress, a connected
 * account, a post, and two or more members or a pending invitation. They run at
 * workspace level, ignoring per-user step permissions, because a workspace with
 * every step done looked complete to every user.
 */
return new class extends Migration
{
    private const string Step = 'enable_sync';

    public function up(): void
    {
        DB::table('workspaces')
            ->whereNotNull('onboarding_progress')
            ->select(['id', 'onboarding_progress'])
            ->chunkById(500, function ($workspaces): void {
                foreach ($workspaces as $workspace) {
                    $progress = json_decode((string) $workspace->onboarding_progress, true);

                    if (! is_array($progress)
                        || in_array(self::Step, $progress, true)
                        || ! $this->wasCompleteUnderOldRules($workspace->id, $progress)) {
                        continue;
                    }

                    DB::table('workspaces')
                        ->where('id', $workspace->id)
                        ->update(['onboarding_progress' => json_encode([...$progress, self::Step])]);
                }
            });
    }

    /**
     * No-op: once the migration runs, a workspace may also earn "enable_sync"
     * legitimately, and a recorded value cannot be told apart from one written
     * here. Leaving the value is harmless on older code, which ignores it.
     */
    public function down(): void {}

    /**
     * @param  array<int, mixed>  $progress
     */
    private function wasCompleteUnderOldRules(string $workspaceId, array $progress): bool
    {
        if (! in_array('timezone', $progress, true)) {
            return false;
        }

        if (! DB::table('connected_accounts')->where('workspace_id', $workspaceId)->exists()) {
            return false;
        }

        if (! DB::table('posts')->where('workspace_id', $workspaceId)->exists()) {
            return false;
        }

        return DB::table('workspace_memberships')->where('workspace_id', $workspaceId)->count() >= 2
            || DB::table('workspace_invitations')->where('workspace_id', $workspaceId)->exists();
    }
};
