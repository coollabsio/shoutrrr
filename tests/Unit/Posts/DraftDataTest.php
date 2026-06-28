<?php

use App\Dto\Post\DraftData;

test('it builds from a full payload', function () {
    $data = DraftData::fromArray([
        'base_text' => 'hello',
        'destination' => ['kind' => 'account', 'id' => 'acc-1'],
        'targets' => [
            ['connected_account_id' => 'acc-1', 'auto_split' => false, 'content_override' => ['text' => 'hi x']],
        ],
        'media_ids' => ['m1', 'm2'],
        'expected_updated_at' => '2026-06-12T10:00:00+00:00',
    ]);

    expect($data->segments)->toBe(['hello'])
        ->and($data->destinationKind)->toBe('account')
        ->and($data->destinationId)->toBe('acc-1')
        ->and($data->mediaIds)->toBe(['m1', 'm2'])
        ->and($data->autoSplitFor('acc-1'))->toBeFalse()
        ->and($data->hasOverrideFor('acc-1'))->toBeTrue()
        ->and($data->overrideFor('acc-1'))->toBe(['segments' => ['hi x'], 'media_ids' => []])
        ->and($data->expectedUpdatedAt)->toBe('2026-06-12T10:00:00+00:00');
});

test('it prefers segments over base_text when both are sent', function () {
    $data = DraftData::fromArray([
        'base_text' => 'ignored',
        'segments' => ['part one', 'part two'],
        'destination' => ['kind' => 'all'],
    ]);

    expect($data->segments)->toBe(['part one', 'part two']);
});

test('it defaults missing pieces sensibly', function () {
    $data = DraftData::fromArray([
        'base_text' => '',
        'destination' => ['kind' => 'all'],
    ]);

    expect($data->destinationKind)->toBe('all')
        ->and($data->destinationId)->toBeNull()
        ->and($data->mediaIds)->toBe([])
        ->and($data->autoSplitFor('whatever'))->toBeTrue()
        ->and($data->hasOverrideFor('whatever'))->toBeFalse()
        ->and($data->overrideFor('whatever'))->toBeNull();
});

test('an empty or null content_override yields no override, not an empty segment', function () {
    $data = DraftData::fromArray([
        'base_text' => 'body',
        'destination' => ['kind' => 'all'],
        'targets' => [
            ['connected_account_id' => 'acc-empty', 'content_override' => []],
            ['connected_account_id' => 'acc-null', 'content_override' => null],
        ],
    ]);

    expect($data->hasOverrideFor('acc-empty'))->toBeTrue()
        ->and($data->overrideFor('acc-empty'))->toBeNull()
        ->and($data->hasOverrideFor('acc-null'))->toBeTrue()
        ->and($data->overrideFor('acc-null'))->toBeNull();
});
