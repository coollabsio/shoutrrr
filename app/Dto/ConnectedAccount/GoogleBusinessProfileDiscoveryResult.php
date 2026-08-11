<?php

declare(strict_types=1);

namespace App\Dto\ConnectedAccount;

final readonly class GoogleBusinessProfileDiscoveryResult
{
    /**
     * @param  list<GoogleBusinessProfileLocation>  $locations
     * @param  list<GoogleBusinessProfileReadinessIssue>  $issues
     */
    public function __construct(public array $locations, public array $issues = []) {}
}
