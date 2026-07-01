<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\PostMedia;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class GifToMp4Converter
{
    private const int TIMEOUT_SECONDS = 300;

    private const array CRF_STEPS = [23, 28, 33, 38];

    public function convert(PostMedia $media, int $maxBytes): ConvertedVideo
    {
        if ($media->mime !== 'image/gif') {
            throw new GifToMp4ConversionFailed('Only GIF media can be converted to MP4.');
        }

        $disk = Storage::disk($media->disk);
        $derivedPath = $this->derivedPath($media);

        if ($disk->exists($derivedPath)) {
            $size = (int) $disk->size($derivedPath);

            if ($size <= $maxBytes) {
                return new ConvertedVideo($media->disk, $derivedPath, $size);
            }

            $disk->delete($derivedPath);
        }

        $tempDir = storage_path('app/tmp/gif-to-mp4/'.Str::uuid());
        File::ensureDirectoryExists($tempDir);

        $input = $tempDir.'/input.gif';
        $output = $tempDir.'/output.mp4';

        try {
            $stream = $disk->readStream($media->path);

            if ($stream === null) {
                throw new GifToMp4ConversionFailed('Could not read GIF media.');
            }

            file_put_contents($input, stream_get_contents($stream));
            fclose($stream);

            $this->encode($input, $output, $maxBytes);

            $outputStream = fopen($output, 'rb');

            if ($outputStream === false) {
                throw new GifToMp4ConversionFailed('Could not read converted GIF video.');
            }

            $disk->put($derivedPath, $outputStream);
            fclose($outputStream);

            $size = (int) $disk->size($derivedPath);

            if ($size > $maxBytes) {
                $disk->delete($derivedPath);

                throw new GifToMp4OutputTooLarge('Converted GIF exceeds the Bluesky video size limit.');
            }

            return new ConvertedVideo($media->disk, $derivedPath, $size);
        } finally {
            File::deleteDirectory($tempDir);
        }
    }

    private function derivedPath(PostMedia $media): string
    {
        return 'media/'.$media->workspace_id.'/derived/'.$media->id.'.mp4';
    }

    private function encode(string $input, string $output, int $maxBytes): void
    {
        foreach (self::CRF_STEPS as $crf) {
            @unlink($output);

            $process = new Process([
                'ffmpeg',
                '-hide_banner',
                '-loglevel',
                'error',
                '-y',
                '-i',
                $input,
                '-an',
                '-movflags',
                '+faststart',
                '-pix_fmt',
                'yuv420p',
                '-vf',
                'scale=trunc((iw+1)/2)*2:trunc((ih+1)/2)*2',
                '-c:v',
                'libx264',
                '-preset',
                'veryfast',
                '-crf',
                (string) $crf,
                $output,
            ]);
            $process->setTimeout(self::TIMEOUT_SECONDS);

            try {
                $process->mustRun();
            } catch (ProcessFailedException|ProcessTimedOutException $e) {
                throw new GifToMp4ConversionFailed('Could not convert GIF to MP4.', previous: $e);
            }

            if (! is_file($output) || filesize($output) === 0) {
                throw new GifToMp4ConversionFailed('Converted GIF video was empty.');
            }

            if ((int) filesize($output) <= $maxBytes) {
                return;
            }
        }

        throw new GifToMp4OutputTooLarge('Converted GIF exceeds the Bluesky video size limit.');
    }
}
