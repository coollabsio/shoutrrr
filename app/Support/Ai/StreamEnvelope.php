<?php

declare(strict_types=1);

namespace App\Support\Ai;

use Closure;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StreamEnvelope
{
    public static function response(Closure $producer): StreamedResponse
    {
        return new StreamedResponse(function () use ($producer): void {
            $emit = function (string $type, array $data = []): void {
                echo 'data: '.json_encode([...['type' => $type], ...$data]).PHP_EOL.PHP_EOL;
                flush();
            };

            try {
                $producer($emit);
            } catch (\Throwable $e) {
                $emit('error', ['message' => $e->getMessage()]);
            } finally {
                $emit('done');
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            // Disable nginx/proxy buffering so frames flush incrementally.
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
