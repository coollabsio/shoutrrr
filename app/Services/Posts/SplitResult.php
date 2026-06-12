<?php

declare(strict_types=1);

namespace App\Services\Posts;

final class SplitResult
{
    /**
     * @param  list<string>  $sections
     * @param  list<string>  $issues  advisory validation issue kinds
     */
    public function __construct(
        public readonly array $sections,
        public readonly array $issues,
    ) {}
}
