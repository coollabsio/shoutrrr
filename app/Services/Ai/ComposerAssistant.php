<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Generator;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;

class ComposerAssistant
{
    public function __construct(private AiManager $ai) {}

    public function rewrite(string $text, ?string $platform, int $limit): Generator
    {
        return $this->stream(Prompts::rewrite($platform, $limit), $text);
    }

    public function preset(string $action, string $text, ?string $platform, int $limit): Generator
    {
        return $this->stream(Prompts::preset($action, $platform, $limit), $text);
    }

    public function generate(string $instruction, ?string $platform, int $limit): Generator
    {
        return $this->stream(Prompts::generate($platform, $limit), $instruction);
    }

    public function adapt(string $text, string $platform, int $limit): Generator
    {
        return $this->stream(Prompts::adapt($platform, $limit), $text);
    }

    /**
     * @return Generator<int, string>
     */
    private function stream(string $system, string $prompt): Generator
    {
        $request = $this->ai->textRequest()
            ->withSystemPrompt($system)
            ->withPrompt($prompt);

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
