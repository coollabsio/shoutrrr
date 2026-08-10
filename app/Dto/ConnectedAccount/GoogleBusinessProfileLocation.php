<?php

declare(strict_types=1);

namespace App\Dto\ConnectedAccount;

final readonly class GoogleBusinessProfileLocation
{
    /** @param list<GoogleBusinessProfileReadinessIssue> $readinessIssues */
    public function __construct(
        public string $key,
        public string $accountResourceName,
        public string $locationResourceName,
        public string $title,
        public ?string $storeCode,
        public ?string $addressLabel,
        public ?string $mapsUri,
        public bool $canOperateLocalPost,
        public array $readinessIssues = [],
    ) {}

    /** @return array<string, mixed> */
    public function toBrowserArray(): array
    {
        return ['key' => $this->key, 'title' => $this->title, 'storeCode' => $this->storeCode, 'addressLabel' => $this->addressLabel, 'mapsUri' => $this->mapsUri, 'canOperateLocalPost' => $this->canOperateLocalPost, 'readinessIssues' => array_map(fn (GoogleBusinessProfileReadinessIssue $issue): array => $issue->toArray(), $this->readinessIssues)];
    }
}
