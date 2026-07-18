<?php

declare(strict_types=1);

use App\Services\Legal\LegalHtmlSanitizer;

function sanitize(string $html): string
{
    return (string) new LegalHtmlSanitizer()->sanitize($html);
}

test('keeps the allowed formatting elements', function (): void {
    $html = sanitize('<h2>Heading</h2><p>A <strong>bold</strong> and <em>italic</em> line.</p><ul><li>one</li><li>two</li></ul>');

    expect($html)
        ->toContain('<h2>Heading</h2>')
        ->toContain('<strong>bold</strong>')
        ->toContain('<em>italic</em>')
        ->toContain('<ul><li>one</li><li>two</li></ul>');
});

test('keeps safe links and forces a hardened rel', function (): void {
    $html = sanitize('<p><a href="https://example.com">site</a></p>');

    expect($html)
        ->toContain('href="https://example.com"')
        ->toContain('rel="nofollow noopener noreferrer"');
});

test('drops script tags and their contents', function (): void {
    $html = sanitize('<p>ok</p><script>alert(1)</script>');

    expect($html)
        ->toBe('<p>ok</p>')
        ->not->toContain('alert(1)');
});

test('strips event-handler and style attributes', function (): void {
    $html = sanitize('<p onclick="steal()" style="color:red">text</p>');

    expect($html)
        ->toContain('text')
        ->not->toContain('onclick')
        ->not->toContain('style=');
});

test('removes javascript and other unsafe link schemes', function (): void {
    $html = sanitize('<p><a href="javascript:alert(1)">x</a></p>');

    expect($html)->not->toContain('javascript:');
});

test('drops media and framing elements', function (): void {
    $html = sanitize('<p>hi</p><img src="x" onerror="alert(1)"><iframe src="https://evil"></iframe>');

    expect($html)
        ->toBe('<p>hi</p>')
        ->not->toContain('<img')
        ->not->toContain('<iframe');
});

test('returns null for blank or content-free input', function (mixed $input): void {
    expect(new LegalHtmlSanitizer()->sanitize($input))->toBeNull();
})->with([
    'null' => [null],
    'empty' => [''],
    'whitespace' => ['   '],
    'empty paragraph' => ['<p></p>'],
    'whitespace paragraph' => ['<p>  </p>'],
    'markup only' => ['<ul><li></li></ul>'],
]);
