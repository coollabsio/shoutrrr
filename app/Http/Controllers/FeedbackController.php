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
            'diagnostics' => ['nullable', 'file', 'max:5120'], // KB → 5 MB
        ]);

        /** @var User $user */
        $user = $request->user();
        $workspace = $user->currentWorkspace;
        $rawUrl = $validated['url'] ?? 'unknown';

        $this->feedback->send(new FeedbackReport(
            type: FeedbackType::from($validated['type']),
            message: $validated['message'],
            url: $this->presentableUrl($rawUrl),
            browser: $validated['browser'] ?? 'unknown',
            environment: app()->environment(),
            userName: $user->name,
            userEmail: $user->email,
            // Larastan infers currentWorkspace as never-null from the BelongsTo
            // generic, but current_workspace_id is nullable in practice (e.g.
            // after the user's workspace is deleted), so the nullsafe fallback
            // here is real and must stay.
            workspaceName: $workspace?->name ?? 'unknown', // @phpstan-ignore nullsafe.neverNull
            workspaceId: $workspace?->id ?? 'unknown', // @phpstan-ignore nullsafe.neverNull
            subscriptionStatus: $this->subscriptionStatus($workspace),
            screenshotBytes: $this->screenshotBytes($request),
            diagnosticsJson: $this->diagnosticsJson($request, $rawUrl),
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

    /**
     * On self-hosted instances the page host reveals the operator's private
     * domain, so strip the scheme/host/credentials and keep only the path
     * (plus query/fragment) — enough to know which screen without leaking
     * where the instance lives. Cloud reports keep the full URL.
     */
    private function presentableUrl(string $url): string
    {
        if (! config('instance.self_hosted') || $url === 'unknown') {
            return $url;
        }

        $parts = parse_url($url);

        if ($parts === false) {
            return '(hidden)';
        }

        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return $path.$query.$fragment;
    }

    private function screenshotBytes(Request $request): ?string
    {
        if (! $request->hasFile('screenshot')) {
            return null;
        }

        $contents = $request->file('screenshot')->get();

        return $contents === false ? null : $contents;
    }

    /**
     * Read the attached diagnostics JSON. On self-hosted instances the captured
     * network/navigation URLs are same-origin, so replace every occurrence of
     * the operator's host throughout the payload — hiding the private host
     * (regardless of scheme or port) while keeping paths and any third-party
     * hosts intact. The host is taken from the page URL, falling back to the
     * request host so redaction never silently fails open.
     */
    private function diagnosticsJson(Request $request, string $rawUrl): ?string
    {
        if (! $request->hasFile('diagnostics')) {
            return null;
        }

        $contents = $request->file('diagnostics')->get();

        if ($contents === false) {
            return null;
        }

        if (config('instance.self_hosted')) {
            // Redact both the client-claimed host and the authoritative request
            // host — they usually match, but if the client URL is stale/tampered
            // the real same-origin host in the payload must still be scrubbed.
            $hosts = array_unique(array_filter([
                $this->hostOf($rawUrl),
                $request->getHost(),
            ]));

            foreach ($hosts as $host) {
                $contents = str_replace($host, '[host]', $contents);
            }
        }

        return $contents;
    }

    private function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }
}
