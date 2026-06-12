<?php

declare(strict_types=1);

namespace App\Services\Posts;

use RuntimeException;

final class PostStaleWriteException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This draft was changed elsewhere. Refresh to see the latest version.');
    }
}
