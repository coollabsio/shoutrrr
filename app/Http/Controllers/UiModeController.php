<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\UiMode;
use App\Http\Requests\UpdateUiModeRequest;
use App\Models\User;
use App\Support\ProductHome;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class UiModeController extends Controller
{
    /**
     * Persist Agentic or Classic and land on that mode's home.
     */
    public function update(UpdateUiModeRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $mode = UiMode::from($request->validated('mode'));

        $user->forceFill(['ui_mode' => $mode])->save();

        $message = $mode === UiMode::Classic
            ? 'Classic is your home now. Agentic stays one click away.'
            : 'Agentic is your home now. Classic stays in the sidebar.';

        Inertia::flash('toast', ['type' => 'success', 'message' => __($message)]);

        return ProductHome::redirect($user);
    }
}
