<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\ComposerAssistRequest;
use App\Services\Ai\ComposerAssistant;
use App\Support\Ai\StreamEnvelope;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ComposerAssistantController extends Controller
{
    public function __construct(private ComposerAssistant $assistant) {}

    public function rewrite(ComposerAssistRequest $request): StreamedResponse
    {
        $text = (string) $request->string('text');
        $action = $request->string('action')->toString();
        $platform = $request->platform();
        $limit = $request->limit();

        $generator = $action !== ''
            ? $this->assistant->preset($action, $text, $platform, $limit)
            : $this->assistant->rewrite($text, $platform, $limit);

        return $this->streamText($generator);
    }

    public function generate(ComposerAssistRequest $request): StreamedResponse
    {
        $generator = $this->assistant->generate(
            (string) $request->string('instruction'),
            $request->platform(),
            $request->limit(),
        );

        return $this->streamText($generator);
    }

    public function adapt(ComposerAssistRequest $request): StreamedResponse
    {
        $generator = $this->assistant->adapt(
            (string) $request->string('text'),
            (string) ($request->platform() ?? 'x'),
            $request->limit(),
        );

        return $this->streamText($generator);
    }

    /**
     * @param  \Generator<int, string>  $generator
     */
    private function streamText(\Generator $generator): StreamedResponse
    {
        return StreamEnvelope::response(function (callable $emit) use ($generator): void {
            foreach ($generator as $delta) {
                $emit('delta', ['text' => $delta]);
            }
        });
    }
}
