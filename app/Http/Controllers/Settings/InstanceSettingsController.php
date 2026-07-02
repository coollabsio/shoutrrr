<?php

namespace App\Http\Controllers\Settings;

use App\Enums\InstanceRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreInstanceOwnerRequest;
use App\Http\Requests\Settings\UpdateInstancePollingSettingsRequest;
use App\Http\Requests\Settings\UpdateInstanceSettingsRequest;
use App\Models\User;
use App\Support\InstanceSettings;
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

    public function polling(Request $request, InstanceSettings $settings): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user?->isInstanceOwner(), 403);

        return Inertia::render('settings/instance-polling', [
            'settings' => $settings->polling(),
        ]);
    }

    public function update(UpdateInstanceSettingsRequest $request, InstanceSettings $settings): RedirectResponse
    {
        $settings->update($request->instanceSettings());

        return back()->with('success', 'Instance settings updated.');
    }

    public function updatePolling(UpdateInstancePollingSettingsRequest $request, InstanceSettings $settings): RedirectResponse
    {
        $settings->update($request->instancePollingSettings());

        return back()->with('success', 'Polling settings updated.');
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
}
