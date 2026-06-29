<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\PostTargetReply;
use Generator;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;

class ReplyAssistant
{
    public function __construct(private AiManager $ai) {}

    /**
     * @return Generator<int, string>
     */
    public function suggest(PostTargetReply $reply, ?string $postExcerpt, string $tone, int $limit): Generator
    {
        $platform = $reply->platform->value;

        $context = "Original post:\n".($postExcerpt ?? '(unavailable)')."\n\n"
            ."Incoming comment from @{$reply->author_handle}:\n{$reply->text}\n\n"
            .'Draft a reply.';

        $request = $this->ai->textRequest()
            ->withSystemPrompt(Prompts::reply($platform, $limit, $tone))
            ->withPrompt($context);

        foreach ($request->asStream() as $chunk) {
            if ($chunk instanceof ErrorEvent) {
                throw new AiStreamException(
                    $chunk->message !== '' ? $chunk->message : 'The AI provider returned an error.'
                );
            }

            if ($chunk instanceof TextDeltaEvent && $chunk->delta !== '') {
                yield $chunk->delta;
            }
        }
    }
}
