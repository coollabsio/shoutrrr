<?php

use Illuminate\Support\Facades\Route;

test('manual engagement refresh route is disabled', function (): void {
    expect(Route::has('engagement.refresh'))->toBeFalse();
});
