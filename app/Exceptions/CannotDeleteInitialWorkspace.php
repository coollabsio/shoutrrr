<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class CannotDeleteInitialWorkspace extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The initial workspace of this instance cannot be deleted.');
    }
}
