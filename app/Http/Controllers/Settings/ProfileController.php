<?php

namespace App\Http\Controllers\Settings;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\FileStorage;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => config('auth.email_verification.enabled', false) && $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();
        unset($validated['photo']);

        $user->fill($validated);

        if ($request->hasFile('photo')) {
            $oldAvatarPath = $user->avatar_path;
            $disk = FileStorage::publicImageDiskName();
            $path = $request->file('photo')->store('profile-photos', $disk);

            if ($path === false) {
                return back()->withErrors(['photo' => 'The profile photo could not be saved.']);
            }

            $user->avatar_path = $path;

            if ($oldAvatarPath) {
                FileStorage::disk($disk)->delete($oldAvatarPath);
            }
        }

        if (config('auth.email_verification.enabled', false) && $user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $ownedMemberships = $user->workspaceMemberships()
            ->where('role', WorkspaceRole::Owner->value)
            ->get();

        // Block deletion while the user is the sole owner of a workspace that still
        // has other members — they must transfer ownership or delete it first.
        $blocking = $ownedMemberships->filter(
            fn (WorkspaceMembership $membership): bool => WorkspaceMembership::where('workspace_id', $membership->workspace_id)->count() > 1
        );

        if ($blocking->isNotEmpty()) {
            return back()->withErrors([
                'password' => 'Transfer ownership or delete workspaces where you are the only owner before deleting your account.',
            ]);
        }

        DB::transaction(function () use ($user, $ownedMemberships): void {
            // After the guard, every workspace this user owns is single-member, so
            // deleting it is safe and clears the restricted owner_id foreign key.
            Workspace::whereIn('id', $ownedMemberships->pluck('workspace_id'))
                ->each(fn (Workspace $workspace) => $workspace->delete());

            Auth::logout();

            $user->delete();
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
