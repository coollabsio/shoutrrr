<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\User;
use App\Services\Api\ApiKeyManager;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApiKeysController extends Controller
{
    public function __construct(private readonly ApiKeyManager $manager) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $user->currentWorkspace;
        abort_if($workspace === null, 404);
        $this->authorizeManage($user, $workspace->id);

        $keys = ApiKey::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('revoked_at')
            ->latest()
            ->get()
            ->map(fn (ApiKey $key): array => [
                'id' => $key->id,
                'name' => $key->name,
                'scope' => $key->scope,
                'last_used_at' => $key->last_used_at?->toIso8601String(),
                'expires_at' => $key->expires_at?->toIso8601String(),
                'created_at' => $key->created_at->toIso8601String(),
            ]);

        return Inertia::render('settings/api-keys', ['apiKeys' => $keys]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $user->currentWorkspace;
        abort_if($workspace === null, 404);
        $this->authorizeManage($user, $workspace->id);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'scope' => ['required', 'in:read,write'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $expiresAt = ($validated['expires_at'] ?? null) !== null
            ? CarbonImmutable::parse($validated['expires_at'])
            : null;

        [, $plain] = $this->manager->issue($workspace, $user, $validated['name'], $validated['scope'], $expiresAt);

        return back()->with('flash.plainTextApiKey', $plain);
    }

    public function destroy(Request $request, ApiKey $apiKey): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($apiKey->workspace_id === $user->current_workspace_id, 404);
        $this->authorizeManage($user, $apiKey->workspace_id);

        $this->manager->revoke($apiKey);

        return back()->with('success', 'API key revoked.');
    }

    private function authorizeManage(User $user, string $workspaceId): void
    {
        abort_unless($user->hasAllPermissions(['workspace.settings.manage'], $workspaceId), 403);
    }
}
