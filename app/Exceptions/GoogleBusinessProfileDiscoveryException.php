<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Dto\ConnectedAccount\GoogleBusinessProfileReadinessIssue;
use RuntimeException;

class GoogleBusinessProfileDiscoveryException extends RuntimeException
{
    public function __construct(public readonly GoogleBusinessProfileReadinessIssue $issue)
    {
        parent::__construct($issue->message);
    }
}
