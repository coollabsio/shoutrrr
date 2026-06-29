<?php

use App\Services\Ai\Prompts;

it('builds platform- and limit-aware prompts', function () {
    $p = Prompts::rewrite('x', 280);
    expect($p)->toContain('280');
    expect(strtolower($p))->toContain('x');

    expect(Prompts::PRESETS)->toHaveKeys(['shorten', 'expand', 'professional', 'casual', 'punchy', 'fix_grammar']);
    expect(Prompts::preset('shorten', 'linkedin', 3000))->toContain('3000');
    expect(Prompts::reply('bluesky', 300, 'friendly'))->toContain('300');
});

it('rejects an unknown preset', function () {
    expect(fn () => Prompts::preset('nope', null, 0))->toThrow(InvalidArgumentException::class);
});
