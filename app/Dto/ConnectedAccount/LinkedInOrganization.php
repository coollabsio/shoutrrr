<?php

declare(strict_types=1);

namespace App\Dto\ConnectedAccount;

final readonly class LinkedInOrganization
{
    public function __construct(
        public string $id,
        public string $urn,
        public string $name,
        public string $vanityName,
    ) {}
}
