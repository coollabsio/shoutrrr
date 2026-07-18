<?php

use App\Support\FileStorage;
use Illuminate\Support\Facades\Storage;

test('it exposes the configured default disk', function () {
    config(['filesystems.default' => 's3']);
    Storage::fake('s3');

    FileStorage::disk()->put('media/file.txt', 'contents');

    expect(FileStorage::diskName())->toBe('s3');
    Storage::disk('s3')->assertExists('media/file.txt');
});

test('the public image disk falls back to the configured default disk', function () {
    config([
        'filesystems.default' => 's3',
        'filesystems.public_images' => null,
    ]);

    expect(FileStorage::publicImageDiskName())->toBe('s3');
});

test('the public image disk can be configured separately', function () {
    config(['filesystems.public_images' => 'public-images']);

    expect(FileStorage::publicImageDiskName())->toBe('public-images');
});

test('it returns a plain url for a public disk', function () {
    Storage::fake('public');

    expect(FileStorage::url('media/image.jpg', 'public'))
        ->toContain('media/image.jpg')
        ->not->toContain('expiration=');
});

test('it returns a temporary url for a private disk', function () {
    Storage::fake('s3');

    expect(FileStorage::url('media/video.mp4', 's3'))
        ->toContain('media/video.mp4')
        ->toContain('expiration=');
});
