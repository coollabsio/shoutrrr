<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class PruneAbandonedUploads extends Command
{
    protected $signature = 'media:prune-uploads';

    protected $description = 'Delete abandoned presigned-upload tmp files under tmp/media/ older than 24 hours.';

    public function handle(): int
    {
        $disk = Storage::disk(config('media.disk'));
        $cutoff = Carbon::now()->subHours(24)->getTimestamp();
        $deleted = 0;

        foreach ($disk->allFiles('tmp/media') as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
                $deleted++;
            }
        }

        $this->info("Pruned {$deleted} abandoned upload file(s).");

        return self::SUCCESS;
    }
}
