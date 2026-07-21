<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PostMedia;
use App\Support\FileStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class PruneAbandonedUploads extends Command
{
    protected $signature = 'media:prune-uploads';

    protected $description = 'Delete abandoned presigned-upload tmp files under tmp/media/ older than 6 hours.';

    public function handle(): int
    {
        $disk = FileStorage::disk();
        $cutoff = Carbon::now()->subHours(6)->getTimestamp();
        $deleted = 0;

        // allFiles()/listContents() does not honor the disk's `throw => false`
        // flag, so a transient S3 listing error (throttling, timeout, creds)
        // throws here and would fail the whole scheduled run. Keep it best-effort:
        // log and fall through to the DB-orphan prune, which retries next hour.
        try {
            foreach ($disk->allFiles('tmp/media') as $file) {
                // lastModified() DOES honor `throw => false` and returns false
                // (coerced to 0) on a per-object error; require a positive
                // timestamp so we never treat an unreadable mtime as "ancient"
                // and delete a file we could not actually inspect.
                $lastModified = $disk->lastModified($file);

                if ($lastModified > 0 && $lastModified < $cutoff) {
                    $disk->delete($file);
                    $deleted++;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Skipping tmp/media prune after a storage error: '.$e->getMessage());
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
