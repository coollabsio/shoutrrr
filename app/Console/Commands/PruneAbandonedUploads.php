<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PostMedia;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PruneAbandonedUploads extends Command
{
    protected $signature = 'media:prune-uploads';

    protected $description = 'Delete abandoned presigned-upload tmp files under tmp/media/ older than 6 hours.';

    public function handle(): int
    {
        $disk = Storage::disk(config('filesystems.default'));
        $cutoff = Carbon::now()->subHours(6)->getTimestamp();
        $deleted = 0;

        foreach ($disk->allFiles('tmp/media') as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
                $deleted++;
            }
        }

        if ($deleted > 0) {
            Log::info("Pruned {$deleted} abandoned upload file(s).");
        }

        $this->info("Pruned {$deleted} abandoned upload file(s).");

        $orphans = PostMedia::query()->withoutGlobalScopes()
            ->whereNull('post_id')
            ->where('created_at', '<', Carbon::now()->subHours(6))
            ->get();

        foreach ($orphans as $orphan) {
            $orphan->delete(); // model's deleting hook removes the underlying file(s)
        }

        if ($orphans->isNotEmpty()) {
            Log::info('Pruned '.$orphans->count().' orphan media record(s).');
        }

        return self::SUCCESS;
    }
}
