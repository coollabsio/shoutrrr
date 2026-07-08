<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

/**
 * Passport needs exactly one "personal access" grant client to mint the personal
 * access tokens that back workspace API keys (see App\Services\Api\ApiKeyManager).
 * The MCP OAuth flow uses dynamic client registration, so no such client is
 * created elsewhere — without this, the first key creation throws
 * "Personal access client not found". Seeding it here guarantees every
 * environment has one after `php artisan migrate`. Idempotent: a no-op when a
 * personal access client already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->personalAccessClientExists()) {
            return;
        }

        app(ClientRepository::class)->createPersonalAccessGrantClient(
            config('app.name').' Personal Access Client'
        );
    }

    public function down(): void
    {
        Client::query()
            ->where('name', config('app.name').' Personal Access Client')
            ->get()
            ->each(fn (Client $client): ?bool => $client->delete());
    }

    private function personalAccessClientExists(): bool
    {
        return Client::query()
            ->where('revoked', false)
            ->get()
            ->contains(fn (Client $client): bool => $client->hasGrantType('personal_access'));
    }
};
