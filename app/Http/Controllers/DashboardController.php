<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Models\WorkspaceMention;
use App\Support\Onboarding\OnboardingPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * The compose-first home.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $workspace = $user?->currentWorkspace;

        return Inertia::render('dashboard', [
            'onboarding' => $workspace instanceof Workspace
                ? OnboardingPresenter::make($workspace, $user)
                : null,
            'savedMentions' => $user?->current_workspace_id
                ? WorkspaceMention::withoutGlobalScopes()
                    ->where('workspace_id', $user->current_workspace_id)
                    ->orderBy('name')
                    ->get()
                    ->map(fn (WorkspaceMention $mention): array => WorkspaceMentionController::view($mention))
                    ->all()
                : [],
        ]);
    }
}
