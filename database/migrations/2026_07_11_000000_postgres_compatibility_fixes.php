<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * PostgreSQL compatibility fixes for schema shipped in v1.0.0–v1.2.0.
     *
     * The app was developed against SQLite, whose loose typing hid two issues
     * that PostgreSQL (strict typing) rejects. Because the offending columns
     * were created by already-released migrations, they are corrected here with
     * in-place ALTERs rather than by editing history, so existing deployments
     * upgrade with a plain `migrate` and no data loss.
     *
     *  1. `notifications.data` was `text`, but the notification feed filters on
     *     `data->workspace_id`. PostgreSQL only exposes `->`/`->>` on json/jsonb,
     *     so a text column raised "operator does not exist: text ->> unknown".
     *     `jsonb` fixes it and gives indexable, efficient lookups.
     *
     *  2. Passport's default schema types `user_id` (and the `oauth_clients`
     *     morph `owner_id`) as `bigint`, but this app keys users by UUID. On
     *     PostgreSQL those columns reject UUID values, breaking every API token
     *     and MCP OAuth issuance ("invalid input syntax for type bigint").
     *
     * All statements are PostgreSQL-only: MySQL and SQLite evaluate JSON paths
     * against text and tolerate UUIDs in these columns, so no change is needed
     * there. The oauth token/auth-code/device-code tables cannot hold rows on
     * PostgreSQL (every insert failed), and `oauth_clients.owner_id` is only ever
     * NULL for the same reason, so `USING NULL::uuid` is lossless here. Each
     * ALTER is guarded by the current column type, so the migration is a safe
     * no-op if a column is already the target type.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if ($this->columnType('notifications', 'data') !== 'jsonb') {
            DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE jsonb USING data::jsonb');
        }

        $this->bigintColumnToUuid('oauth_access_tokens', 'user_id');
        $this->bigintColumnToUuid('oauth_auth_codes', 'user_id');
        $this->bigintColumnToUuid('oauth_device_codes', 'user_id');
        $this->bigintColumnToUuid('oauth_clients', 'owner_id');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if ($this->columnType('notifications', 'data') === 'jsonb') {
            DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE text USING data::text');
        }

        // The oauth columns are intentionally not reverted: `bigint` was never a
        // correct type for a UUID-keyed users table, and there is no meaningful
        // bigint value to restore the UUIDs to.
    }

    private function bigintColumnToUuid(string $table, string $column): void
    {
        if ($this->columnType($table, $column) === 'bigint') {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE uuid USING NULL::uuid");
        }
    }

    private function columnType(string $table, string $column): ?string
    {
        $result = DB::selectOne(
            'SELECT data_type FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            [$table, $column]
        );

        return $result?->data_type;
    }
};
