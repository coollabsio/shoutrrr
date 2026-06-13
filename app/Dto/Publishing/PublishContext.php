<?php

declare(strict_types=1);

namespace App\Dto\Publishing;

use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;

final readonly class PublishContext
{
    /**
     * @param  list<string>  $segments
     * @param  list<PostMedia>  $media
     * @param  array<string, mixed>  $credentials
     */
    public function __construct(
        public PostTarget $target,
        public array $segments,
        public array $media,
        public ConnectedAccount $account,
        public array $credentials,
    ) {}
}
