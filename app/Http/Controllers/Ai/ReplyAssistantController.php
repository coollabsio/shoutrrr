<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\SuggestReplyRequest;
use App\Models\PostTargetReply;
use App\Services\Ai\ReplyAssistant;
use App\Support\Ai\StreamEnvelope;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReplyAssistantController extends Controller
{
    public function __construct(private ReplyAssistant $assistant) {}

    public function suggest(SuggestReplyRequest $request, PostTargetReply $reply): StreamedResponse
    {
        $tone = $request->string('tone')->toString() ?: 'friendly';
        $excerpt = $request->string('post_excerpt')->toString() ?: null;
        $limit = (int) $request->integer('limit');

        $generator = $this->assistant->suggest($reply, $excerpt, $tone, $limit);

        return StreamEnvelope::response(function (callable $emit) use ($generator): void {
            foreach ($generator as $delta) {
                $emit('delta', ['text' => $delta]);
            }
        });
    }
}
