<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connected_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('platform');
            $table->string('handle');
            $table->string('display_name')->nullable();
            $table->text('avatar_url')->nullable();
            $table->string('remote_account_id');
            $table->string('auth_method')->default('oauth');
            $table->foreignUuid('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('active');
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('last_refreshed_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'platform', 'remote_account_id'],'connected_accounts_workspace_platform_remote_unique');
        });

        Schema::create('connected_account_secrets', function (Blueprint $table) {
            $table->foreignUuid('connected_account_id')->primary()
                ->constrained('connected_accounts')->cascadeOnDelete();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->text('app_password')->nullable();
            // Stored via the model's `encrypted:array` cast, so the column holds an
            // opaque ciphertext string — not queryable JSON. `text` keeps this valid
            // on Postgres/MySQL (a `json` column rejects the non-JSON ciphertext).
            $table->text('session')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connected_account_secrets');
        Schema::dropIfExists('connected_accounts');
    }
};
