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

test('the development server binds to all interfaces without a fixed port so it can fall back', function () {
    $composer = json_decode(file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    $concurrently = collect($composer['scripts']['dev'])->first(
        fn (string $script): bool => str_contains($script, 'bunx concurrently'),
    );

    expect($concurrently)
        ->toContain('php artisan serve --host=0.0.0.0')
        ->not->toContain('--port=')
        ->toContain('php artisan queue:listen --tries=1 --timeout=0')
        ->toContain('php artisan pail --timeout=0')
        ->toContain('bun run dev -- --host 0.0.0.0')
        ->toContain('php artisan schedule:work')
        ->toContain('--names=server,queue,logs,vite,cron --kill-others');
});

test('vite binds to all interfaces, falls back when the port is taken, and uses VITE_HMR_HOST', function () {
    $viteConfig = file_get_contents(base_path('vite.config.ts'));
    $packageJson = json_decode(file_get_contents(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($viteConfig)
        ->toContain("host: '0.0.0.0'")
        ->toContain('port: vitePort')
        ->toContain('strictPort: false')
        ->toContain("...loadEnv(mode, process.cwd(), '')")
        ->toContain('...process.env')
        ->toContain("environment.VITE_HMR_HOST || '').trim()")
        ->toContain("appUrl.protocol === 'https:' ? 'localhost' : appUrl.hostname")
        ->toContain('disable-hot-file-for-https-app-url')
        ->toContain('cors: true')
        ->toContain('host: hmrHost')
        ->not->toContain('origin: viteOrigin')
        ->not->toContain('clientPort: vitePort')
        ->not->toContain('strictPort: true')
        ->not->toContain('networkInterfaces');

    expect($packageJson['scripts']['dev'])->toBe('vite --host 0.0.0.0');

    expect(file_get_contents(base_path('.env.example')))
        ->toContain('VITE_HMR_HOST=');
});
