<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Dto\Post\IntentContent;
use App\Exceptions\AgentIntentRejectedException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Posts\AgentIntentService;
use App\Support\PostComposerInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IntentsController extends Controller
{
    public function publishNow(Request $request, AgentIntentService $intents): JsonResponse
    {
        $validated = $request->validate([
            ...$this->draftRules(),
            'scheduled_at' => ['prohibited'],
        ]);

        return $this->respond($request, function (User $user, string $workspaceId, string $key) use ($intents, $validated): array {
            return $intents->publishNow(
                $user,
                $workspaceId,
                $key,
                IntentContent::fromValidated($validated),
                $validated['destination'],
            );
        }, queued: true);
    }

    public function scheduleAt(Request $request, AgentIntentService $intents): JsonResponse
    {
        $validated = $request->validate([
            ...$this->draftRules(),
            'scheduled_at' => ['required', 'date'],
        ]);

        $scheduledAt = $validated['scheduled_at'];
        if (! is_string($scheduledAt)) {
            throw ValidationException::withMessages([
                'scheduled_at' => 'Choose a time in the future — a post cannot be scheduled in the past.',
            ]);
        }

        return $this->respond($request, function (User $user, string $workspaceId, string $key) use ($intents, $validated, $scheduledAt): array {
            return $intents->scheduleAt(
                $user,
                $workspaceId,
                $key,
                IntentContent::fromValidated($validated),
                $validated['destination'],
                $scheduledAt,
            );
        }, queued: false);
    }

    /**
     * @return array<string, mixed>
     */
    private function draftRules(): array
    {
        return [
            'text' => ['required_without:segments', 'nullable', 'string'],
            ...PostComposerInput::contentRules(),
            'destination' => ['required', 'array'],
            'destination.kind' => ['required', Rule::in(['all', 'set', 'account'])],
            'destination.id' => ['nullable', 'string', 'required_if:destination.kind,set,account'],
        ];
    }

    /**
     * @param  callable(User, string, string): array{post: array<string, mixed>, idempotent_replay: bool}  $run
     */
    private function respond(Request $request, callable $run, bool $queued): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspaceId = Context::get('workspace_id');

        if (! is_string($workspaceId) || $workspaceId === '') {
            abort(401, 'This API key is not valid.');
        }

        try {
            $result = $run($user, $workspaceId, $this->idempotencyKey($request));
        } catch (AgentIntentRejectedException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                ...$exception->payload,
            ], $exception->status);
        }

        $body = [
            'idempotent_replay' => $result['idempotent_replay'],
            'post' => $result['post'],
        ];

        if ($queued) {
            $body['status'] = $result['idempotent_replay'] ? 'replayed' : 'queued';
            $body['message'] = $result['idempotent_replay']
                ? 'This idempotency key already published a post. Returning the original result.'
                : 'Publishing started. Poll GET /posts/{id} for per-target status.';

            return response()->json($body, $result['idempotent_replay'] ? 200 : 202);
        }

        return response()->json($body);
    }

    private function idempotencyKey(Request $request): string
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));

        if ($key === '' || strlen($key) > 255 || preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => 'An Idempotency-Key header is required (1–255 characters, no control characters).',
            ]);
        }

        return $key;
    }
}
