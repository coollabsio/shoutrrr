<?php

declare(strict_types=1);

namespace App\Dto\Repost;

use App\Models\ConnectedAccount;
use App\Models\PostTarget;

final readonly class RepostContext
{
    /**
     * @param  array<string, mixed>  $credentials
     */
    public function __construct(
        public PostTarget $target,
        public ConnectedAccount $account,
        public array $credentials,
    ) {}
}
