<?php

declare(strict_types=1);

namespace App\Dto\ConnectedAccount;

use App\Enums\GoogleBusinessProfileReadinessIssueCode;

final readonly class GoogleBusinessProfileReadinessIssue
{
    public function __construct(
        public GoogleBusinessProfileReadinessIssueCode $code,
        public string $message,
        public ?string $service = null,
        public ?string $reason = null,
        public ?int $httpStatus = null,
    ) {}

    /** @return array{code: string, message: string, service: ?string, reason: ?string, httpStatus: ?int} */
    public function toArray(): array
    {
        return ['code' => $this->code->value, 'message' => $this->message, 'service' => $this->service, 'reason' => $this->reason, 'httpStatus' => $this->httpStatus];
    }
}
