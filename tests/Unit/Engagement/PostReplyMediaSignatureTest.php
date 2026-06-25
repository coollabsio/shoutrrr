<?php

use App\Services\Engagement\Contracts\EngagementConnector;

test('postReply accepts a media argument with an empty-array default', function () {
    $method = new ReflectionMethod(EngagementConnector::class, 'postReply');
    $params = $method->getParameters();

    expect($params)->toHaveCount(5);
    expect($params[4]->getName())->toBe('media');
    expect($params[4]->isDefaultValueAvailable())->toBeTrue();
    expect($params[4]->getDefaultValue())->toBe([]);
});
