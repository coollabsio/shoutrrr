<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\PostTarget;
use Illuminate\Foundation\Events\Dispatchable;

class PostTargetPublished
{
    use Dispatchable;

    public function __construct(public PostTarget $target) {}
}
