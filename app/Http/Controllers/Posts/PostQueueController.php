<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Enums\PostStatus;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Services\Billing\WorkspaceSubscriptionGate;
use App\Services\Posts\NextSlotResolver;
use App\Support\PostView;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostQueueController extends Controller
{
    public function __construct(private readonly NextSlotResolver $resolver) {}

    public function store(Request $request, Post $post, WorkspaceSubscriptionGate $subscriptions): JsonResponse
    {
        abort_unless($request->user()->can('update', $post), 403);

        $workspace = $post->workspace()->firstOrFail();

        if (! $subscriptions->canPublish($workspace)) {
            return response()->json([
                'message' => 'Subscribe to publish this post.',
                'billing_url' => route('billing.index'),
            ], 402);
        }

        $validated = $request->validate([
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ]);

        $availableSlots = $this->resolver->availableSlots($workspace);
        $slot = $this->resolveRequestedSlot(
            $availableSlots,
            $validated['scheduled_at'] ?? null,
        );

        if ($slot === null) {
            return response()->json([
                'message' => $request->filled('scheduled_at')
                    ? 'Choose an open slot from your posting queue.'
                    : 'No open posting slot available. Add posting-schedule slots in settings.',
            ], 422);
        }

        $post->scheduled_at = $slot;
        $post->status = PostStatus::Scheduled;
        $post->save();

        return response()->json([
            'post' => PostView::make($post->fresh(['targets.account', 'media'])),
        ]);
    }

    /**
     * @param  list<CarbonImmutable>  $availableSlots
     */
    private function resolveRequestedSlot(array $availableSlots, ?string $requestedSlot): ?CarbonImmutable
    {
        if ($requestedSlot === null) {
            return $availableSlots[0] ?? null;
        }

        $requested = CarbonImmutable::parse($requestedSlot)
            ->setTimezone('UTC')
            ->toIso8601String();

        foreach ($availableSlots as $slot) {
            if ($slot->setTimezone('UTC')->toIso8601String() === $requested) {
                return $slot;
            }
        }

        return null;
    }
}
