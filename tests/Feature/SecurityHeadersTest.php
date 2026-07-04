<?php

use Illuminate\Support\Facades\Vite;

test('responses carry the static security headers', function () {
    $response = $this->get('/login');

    $response->assertOk();
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

test('responses carry a nonce-based content security policy', function () {
    $response = $this->get('/login');

    $csp = $response->headers->get('Content-Security-Policy');

    expect($csp)->not->toBeNull()
        ->and($csp)->toContain("default-src 'self'")
        ->and($csp)->toContain("frame-ancestors 'none'")
        ->and($csp)->toContain("object-src 'none'")
        ->and($csp)->toContain("base-uri 'self'")
        ->and($csp)->toContain("img-src 'self' data: blob: https:")
        ->and($csp)->toContain("media-src 'self' blob:")
        ->and($csp)->toContain("connect-src 'self' blob:")
        ->and($csp)->toMatch("/script-src [^;]*'nonce-[A-Za-z0-9+\/=]+'/")
        ->and($csp)->toContain("'strict-dynamic'");
});

test('a local/public-disk deployment keeps connect-src and media-src tight', function () {
    config(['filesystems.default' => 'public']);

    $csp = $this->get('/login')->headers->get('Content-Security-Policy');

    // No remote storage host, so no origin is appended to either directive.
    expect($csp)->toContain('connect-src \'self\' blob:;')
        ->and($csp)->toContain('media-src \'self\' blob:;');
});

test('a remote s3 disk allowlists its configured endpoint origin for uploads and playback', function () {
    config([
        'filesystems.default' => 's3',
        'filesystems.disks.s3.url' => null,
        'filesystems.disks.s3.endpoint' => 'https://minio.example.com:9000',
    ]);

    $csp = $this->get('/login')->headers->get('Content-Security-Policy');

    // The signed-upload PUT / editor GET and <video> playback both target this
    // origin; it must appear in connect-src and media-src, and not leak to https:.
    expect($csp)->toContain('connect-src \'self\' blob: https://minio.example.com:9000')
        ->and($csp)->toContain('media-src \'self\' blob: https://minio.example.com:9000')
        ->and($csp)->not->toContain('connect-src \'self\' blob: https:;');
});

test('a vanilla s3 disk with no endpoint falls back to https: so uploads still work', function () {
    config([
        'filesystems.default' => 's3',
        'filesystems.disks.s3.url' => null,
        'filesystems.disks.s3.endpoint' => null,
    ]);

    $csp = $this->get('/login')->headers->get('Content-Security-Policy');

    expect($csp)->toContain('connect-src \'self\' blob: https:')
        ->and($csp)->toContain('media-src \'self\' blob: https:');
});

test('the csp nonce is exposed to vite and differs per request', function () {
    $this->get('/login');
    $first = Vite::cspNonce();

    $this->get('/login');
    $second = Vite::cspNonce();

    expect($first)->not->toBeEmpty()
        ->and($second)->not->toBeEmpty()
        ->and($first)->not->toBe($second);
});
