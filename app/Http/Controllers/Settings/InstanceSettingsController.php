<?php

namespace App\Http\Controllers\Settings;

use App\Enums\InstanceRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreInstanceOwnerRequest;
use App\Http\Requests\Settings\UpdateInstanceAiSettingsRequest;
use App\Http\Requests\Settings\UpdateInstanceSettingsRequest;
use App\Models\User;
use App\Services\Ai\AiManager;
use App\Services\Ai\ModelCatalogException;
use App\Services\Ai\ProviderModelCatalog;
use App\Support\InstanceSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InstanceSettingsController extends Controller
{
    public function edit(Request $request, InstanceSettings $settings): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user?->isInstanceOwner(), 403);

        $workspacesEnabled = (bool) config('kit.workspaces.enabled');
        $instanceSettings = $settings->all();

        if (! $workspacesEnabled) {
            $instanceSettings['workspace_creation_enabled'] = false;
        }

        return Inertia::render('settings/instance', [
            'settings' => $instanceSettings,
            'workspaces_enabled' => $workspacesEnabled,
        ]);
    }

    public function admins(Request $request): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user?->isInstanceOwner(), 403);

        $search = $request->string('search')->trim()->toString();

        $users = $search === ''
            ? collect()
            : User::query()
                ->select(['id', 'name', 'email'])
                ->whereNull('instance_role')
                ->where('email', 'like', "%{$search}%")
                ->orderBy('email')
                ->limit(10)
                ->get()
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar,
                ]);

        return Inertia::render('settings/instance-admins', [
            'owners' => User::query()
                ->select(['id', 'name', 'email', 'created_at'])
                ->where('instance_role', InstanceRole::Owner->value)
                ->orderBy('email')
                ->get()
                ->map(fn (User $owner): array => [
                    'id' => $owner->id,
                    'name' => $owner->name,
                    'email' => $owner->email,
                    'avatar' => $owner->avatar,
                    'created_at' => $owner->created_at,
                ]),
            'users' => $users,
            'search' => $search,
        ]);
    }

    public function update(UpdateInstanceSettingsRequest $request, InstanceSettings $settings): RedirectResponse
    {
        $settings->update($request->instanceSettings());

        return back()->with('success', 'Instance settings updated.');
    }

    public function destroyAdmin(Request $request, User $owner): RedirectResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user?->isInstanceOwner(), 403);
        abort_unless($owner->isInstanceOwner(), 404);

        if ($owner->is($user)) {
            return back()->withErrors(['owner' => 'You cannot remove yourself as an instance owner.']);
        }

        if (User::query()->where('instance_role', InstanceRole::Owner->value)->count() <= 1) {
            return back()->withErrors(['owner' => 'At least one instance owner is required.']);
        }

        $owner->update(['instance_role' => null]);

        return back()->with('success', 'Instance owner removed.');
    }

    public function storeAdmin(StoreInstanceOwnerRequest $request): RedirectResponse
    {
        User::query()
            ->where('email', $request->email())
            ->update(['instance_role' => InstanceRole::Owner->value]);

        return back()->with('success', 'Instance owner added.');
    }

    public function editAi(Request $request, InstanceSettings $settings): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user?->isInstanceOwner(), 403);

        return Inertia::render('settings/instance-ai', [
            'settings' => $settings->aiSettings(),
        ]);
    }

    public function updateAi(UpdateInstanceAiSettingsRequest $request, InstanceSettings $settings): RedirectResponse
    {
        $payload = $request->aiSettings();
        $settings->updateAi($payload['values'], $payload['apiKey']);

        return back()->with('success', 'AI settings updated.');
    }

    public function testAi(Request $request, AiManager $ai): RedirectResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user?->isInstanceOwner(), 403);

        try {
            $ai->textRequest()->withPrompt('Reply with the single word: ok')->asText();
        } catch (\Throwable $e) {
            return back()->with('error', 'Connection failed: '.$e->getMessage());
        }

        return back()->with('success', 'Connection succeeded.');
    }

    public function aiModels(Request $request, InstanceSettings $settings, ProviderModelCatalog $catalog): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user?->isInstanceOwner(), 403);

        $validated = $request->validate([
            'provider' => ['required', 'string'],
            'api_key' => ['nullable', 'string'],
        ]);

        $provider = $validated['provider'];
        $apiKey = isset($validated['api_key']) && $validated['api_key'] !== ''
            ? $validated['api_key']
            : $settings->aiApiKey();

        try {
            $models = $catalog->models($provider, $apiKey);
        } catch (ModelCatalogException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['models' => $models]);
    }
}
