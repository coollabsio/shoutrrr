<?php

use App\Models\PostMedia;
use App\Services\Media\GifToMp4ConversionFailed;
use App\Services\Media\GifToMp4Converter;
use Illuminate\Support\Facades\Storage;

test('gif converter reuses an existing derived mp4', function (): void {
    Storage::fake('public');

    $media = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/source.gif',
        'mime' => 'image/gif',
    ]);
    $derived = 'media/'.$media->workspace_id.'/derived/'.$media->id.'.mp4';
    Storage::disk('public')->put($derived, 'mp4-bytes');

    $converted = app(GifToMp4Converter::class)->convert($media, 1000);

    expect($converted->disk)->toBe('public')
        ->and($converted->path)->toBe($derived)
        ->and($converted->sizeBytes)->toBe(strlen('mp4-bytes'));
});

test('gif converter rejects non gif media', function (): void {
    $media = PostMedia::factory()->create(['mime' => 'image/jpeg']);

    expect(fn () => app(GifToMp4Converter::class)->convert($media, 1000))
        ->toThrow(GifToMp4ConversionFailed::class);
});
