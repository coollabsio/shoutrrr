<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class PruneMediaChunks extends Command
{
    protected $signature = 'media:prune-chunks';

    protected $description = 'Delete abandoned resumable-upload chunk files older than 6 hours.';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $cutoff = Carbon::now()->subHours(6)->getTimestamp();
        $deleted = 0;

        foreach ($disk->allFiles('media-chunks') as $file) {
            if (str_ends_with($file, '.part') && $disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
                $deleted++;
            }
        }

        $this->info("Pruned {$deleted} abandoned chunk file(s).");

        return self::SUCCESS;
    }
}
