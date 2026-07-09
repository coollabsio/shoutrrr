<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->boolean('is_initial')->default(false);
        });

        // Backfill: the oldest workspace on the instance is the free "initial"
        // workspace that the billing gate previously resolved with a query.
        $firstWorkspaceId = DB::table('workspaces')
            ->orderBy('created_at')
            ->orderBy('id')
            ->value('id');

        if ($firstWorkspaceId !== null) {
            DB::table('workspaces')->where('id', $firstWorkspaceId)->update(['is_initial' => true]);
        }

        if ($this->supportsPartialIndexes()) {
            DB::statement('CREATE UNIQUE INDEX workspaces_single_initial ON workspaces (is_initial) WHERE is_initial');
        }
    }

    public function down(): void
    {
        if ($this->supportsPartialIndexes()) {
            DB::statement('DROP INDEX IF EXISTS workspaces_single_initial');
        }

        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn('is_initial');
        });
    }

    /**
     * Only the drivers this application actually runs on (SQLite locally and in
     * CI, PostgreSQL in Docker) support partial indexes. MySQL and MariaDB do
     * not, so the invariant stays application-enforced there.
     */
    private function supportsPartialIndexes(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true);
    }
};
