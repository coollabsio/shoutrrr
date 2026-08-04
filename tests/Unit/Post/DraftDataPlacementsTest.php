<?php

// tests/Unit/Post/DraftDataPlacementsTest.php
use App\Dto\Post\DraftData;

test('placements default to canonical, overridden per account', function (): void {
    $data = DraftData::fromArray([
        'segments' => ['a', 'b'],
        'segment_breaks' => ['b1'],
        'placements' => [['media_id' => 'm1', 'segment_ref' => '__head__', 'position' => 0]],
        'destination' => ['kind' => 'all'],
        'targets' => [[
            'connected_account_id' => 'acc-x',
            'placements' => [['media_id' => 'm1', 'segment_ref' => 'b1', 'position' => 0]],
            'segment_breaks' => ['b1'],
        ]],
    ]);

    expect($data->placementsFor('acc-other')[0]['segment_ref'])->toBe('__head__'); // canonical
    expect($data->placementsFor('acc-x')[0]['segment_ref'])->toBe('b1');           // diverged
    expect($data->segmentBreaksFor('acc-other'))->toBe(['b1']);
});
