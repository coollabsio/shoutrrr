<?php

declare(strict_types=1);

namespace App\Jobs\Contracts;

/**
 * A queued job that can release itself back onto the queue — satisfied by the
 * framework's InteractsWithQueue trait. Lets the reply-fetch throttle middleware
 * defer a job without depending on a concrete job class.
 */
interface ReleasableJob
{
    /**
     * @param  \DateTimeInterface|\DateInterval|int  $delay
     * @return mixed
     */
    public function release($delay = 0);
}
