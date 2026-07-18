<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Dto\Feedback\FeedbackReport;
use App\Enums\FeedbackType;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Feedback\FeedbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FeedbackController extends Controller
{
    public function __construct(private FeedbackService $feedback) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::enum(FeedbackType::class)],
            'message' => ['required', 'string', 'max:2000'],
            'url' => ['nullable', 'string', 'max:2048'],
            'browser' => ['nullable', 'string', 'max:512'],
            'screenshot' => ['nullable', 'image', 'max:5120'], // KB → 5 MB
        ]);

        /** @var User $user */
        $user = $request->user();
        $workspace = $user->currentWorkspace;

        $this->feedback->send(new FeedbackReport(
            type: FeedbackType::from($validated['type']),
            message: $validated['message'],
            url: $validated['url'] ?? 'unknown',
            browser: $validated['browser'] ?? 'unknown',
            userName: $user->name,
            userEmail: $user->email,
            workspaceName: $workspace->name,
            workspaceId: $workspace->id,
            subscriptionStatus: $this->subscriptionStatus($workspace),
            screenshotBytes: $this->screenshotBytes($request),
        ));

        return response()->json(['ok' => true]);
    }

    private function subscriptionStatus(?Workspace $workspace): string
    {
        if (! config('subscriptions.enabled')) {
            return 'self-hosted';
        }

        if ($workspace === null) {
            return 'unknown';
        }

        if ($workspace->is_initial) {
            return 'initial (free)';
        }

        return $workspace->subscribed('default') ? 'subscribed' : 'unsubscribed';
    }

    private function screenshotBytes(Request $request): ?string
    {
        if (! $request->hasFile('screenshot')) {
            return null;
        }

        $contents = $request->file('screenshot')->get();

        return $contents === false ? null : $contents;
    }
}
