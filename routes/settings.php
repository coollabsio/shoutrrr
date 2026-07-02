<?php

use App\Http\Controllers\BillingController;
use App\Http\Controllers\Settings\ConnectionsController;
use App\Http\Controllers\Settings\InstanceSettingsController;
use App\Http\Controllers\Settings\NotificationPreferencesController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\WorkspaceSettingsController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('settings/workspace', [WorkspaceSettingsController::class, 'showOverview'])->name('settings.workspace');
    Route::patch('settings/workspace', [WorkspaceSettingsController::class, 'update'])->name('settings.workspace.update');
    Route::put('settings/workspace/timezone', [WorkspaceSettingsController::class, 'updateTimezone'])->name('settings.workspace.timezone');
    Route::get('settings/workspace/members', [WorkspaceSettingsController::class, 'showMembers'])->name('settings.workspace.members');
    Route::post('settings/workspace/invite', [WorkspaceSettingsController::class, 'inviteUser'])->name('settings.workspace.invite');
    Route::patch('settings/workspace/members/{membership}', [WorkspaceSettingsController::class, 'updateMemberRole'])->name('settings.workspace.members.update');
    Route::delete('settings/workspace/members/{membership}', [WorkspaceSettingsController::class, 'removeMember'])->name('settings.workspace.members.remove');
    Route::delete('settings/workspace/invitations/{invitation}', [WorkspaceSettingsController::class, 'cancelInvitation'])->name('settings.workspace.invitations.cancel');

    Route::get('settings/connections', [ConnectionsController::class, 'edit'])->name('connections.edit');
    Route::delete('settings/connections/{socialAccount}', [ConnectionsController::class, 'destroy'])->name('connections.destroy');

    Route::get('settings/notifications', [NotificationPreferencesController::class, 'edit'])->name('notifications.preferences');
    Route::put('settings/notifications', [NotificationPreferencesController::class, 'update'])->name('notifications.preferences.update');

    Route::get('settings/instance', [InstanceSettingsController::class, 'edit'])->name('instance-settings.edit');
    Route::put('settings/instance', [InstanceSettingsController::class, 'update'])->name('instance-settings.update');
    Route::get('settings/instance/polling', [InstanceSettingsController::class, 'polling'])->name('instance-settings.polling');
    Route::put('settings/instance/polling', [InstanceSettingsController::class, 'updatePolling'])->name('instance-settings.polling.update');
    Route::get('settings/instance/admins', [InstanceSettingsController::class, 'admins'])->name('instance-settings.admins');
    Route::post('settings/instance/admins', [InstanceSettingsController::class, 'storeAdmin'])->name('instance-settings.admins.store');
    Route::delete('settings/instance/admins/{owner}', [InstanceSettingsController::class, 'destroyAdmin'])->name('instance-settings.admins.destroy');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings/workspace/subscription', [BillingController::class, 'index'])->name('billing.index');
    Route::post('billing/checkout', [BillingController::class, 'checkout'])->name('billing.checkout');
    Route::post('billing/portal', [BillingController::class, 'portal'])->name('billing.portal');

    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');
});
