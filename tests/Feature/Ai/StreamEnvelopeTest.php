<?php

use App\Support\Ai\StreamEnvelope;

it('emits delta then done as sse frames', function () {
    $response = StreamEnvelope::response(function (callable $emit) {
        $emit('delta', ['text' => 'Hello']);
        $emit('delta', ['text' => ' world']);
    });

    ob_start();
    $response->sendContent();
    $body = ob_get_clean();

    expect($body)->toContain('data: {"type":"delta","text":"Hello"}');
    expect($body)->toContain('data: {"type":"delta","text":" world"}');
    expect($body)->toContain('data: {"type":"done"}');
    expect($response->headers->get('Content-Type'))->toContain('text/event-stream');
});

it('emits an error frame when the emitter throws', function () {
    $response = StreamEnvelope::response(function (callable $emit) {
        $emit('delta', ['text' => 'partial']);
        throw new RuntimeException('boom');
    });

    ob_start();
    $response->sendContent();
    $body = ob_get_clean();

    expect($body)->toContain('"type":"error"');
    expect($body)->toContain('"type":"done"');
});
