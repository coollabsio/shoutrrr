<?php

declare(strict_types=1);

use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\WorkspaceInvitationResponseController;
use Illuminate\Support\Facades\Route;

Route::get('invitation/{token}', [WorkspaceController::class, 'showInvitation'])
    ->middleware('throttle:5,1')
    ->name('workspace.invitation');

Route::middleware('auth')->group(function (): void {
    Route::post('workspace-invitations/{invitation}/accept', [WorkspaceInvitationResponseController::class, 'accept'])
        ->name('workspace.invitations.accept');
    Route::delete('workspace-invitations/{invitation}', [WorkspaceInvitationResponseController::class, 'deny'])
        ->name('workspace.invitations.deny');
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::post('workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');
    Route::post('workspaces/switch', [WorkspaceController::class, 'switch'])->name('workspaces.switch');
    Route::delete('workspaces/{workspace}/leave', [WorkspaceController::class, 'leave'])->name('workspaces.leave');
    Route::delete('workspaces/{workspace}', [WorkspaceController::class, 'destroy'])->name('workspaces.destroy');
    Route::post('workspaces/{workspace}/transfer', [WorkspaceController::class, 'transferOwnership'])->name('workspaces.transfer');
    Route::post('onboarding/welcomed', [OnboardingController::class, 'welcomed'])->name('onboarding.welcomed');
    Route::post('onboarding/dismiss', [OnboardingController::class, 'dismiss'])->name('onboarding.dismiss');
    Route::post('onboarding/step', [OnboardingController::class, 'completeStep'])->name('onboarding.step');
});
