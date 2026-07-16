<?php

it('seeds the default user, engagement inbox, and analytics before starting development services', function (): void {
    $composer = json_decode(file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    $dev = $composer['scripts']['dev'];

    $defaultUser = '@php artisan db:seed --class=DefaultUserSeeder --force --no-interaction';
    $engagement = '@php artisan db:seed --class=DummyEngagementSeeder --force --no-interaction';
    $analytics = '@php artisan db:seed --class=DummyAnalyticsSeeder --force --no-interaction';

    expect($dev)->toContain($defaultUser)
        ->and($dev)->toContain($engagement)
        ->and($dev)->toContain($analytics)
        ->and(array_search($defaultUser, $dev, true))
        ->toBeLessThan(array_search($engagement, $dev, true))
        ->and(array_search($engagement, $dev, true))
        ->toBeLessThan(array_search($analytics, $dev, true));
});
