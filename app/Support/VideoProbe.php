<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Reads a local video's duration and dimensions with ffprobe. Returns null when
 * ffprobe is missing, fails, or finds no positive duration.
 */
class VideoProbe
{
    /**
     * @return array{duration_seconds: int, width: int|null, height: int|null}|null
     */
    public function probe(string $path): ?array
    {
        try {
            $result = Process::timeout(30)->run([
                'ffprobe', '-v', 'error', '-select_streams', 'v:0',
                '-show_entries', 'stream=width,height:format=duration', '-of', 'json', $path,
            ]);
        } catch (Throwable) {
            return null;
        }

        if (! $result->successful()) {
            return null;
        }

        /** @var array{format?: array{duration?: string}, streams?: list<array{width?: int, height?: int}>} $data */
        $data = (array) json_decode($result->output(), true);
        $seconds = (float) ($data['format']['duration'] ?? 0);

        if ($seconds <= 0) {
            return null;
        }

        return [
            'duration_seconds' => (int) ceil($seconds),
            'width' => $data['streams'][0]['width'] ?? null,
            'height' => $data['streams'][0]['height'] ?? null,
        ];
    }
}
