<?php

declare(strict_types=1);

namespace App\Dto\Publishing;

use App\Enums\ErrorKind;

final readonly class PublishResult
{
    /**
     * @param  list<string>  $remoteIds
     */
    public function __construct(
        public array $remoteIds,
        public ?ErrorKind $errorKind = null,
        public ?string $errorMessage = null,
        public ?int $httpStatus = null,
        public ?string $responseExcerpt = null,
        public ?int $retryAfter = null,
    ) {}

    /**
     * @param  list<string>  $remoteIds
     */
    public static function success(array $remoteIds): self
    {
        return new self(remoteIds: $remoteIds);
    }

    public static function failure(ErrorKind $kind, string $message, ?int $httpStatus = null, ?string $excerpt = null, ?int $retryAfter = null): self
    {
        return new self(
            remoteIds: [],
            errorKind: $kind,
            errorMessage: $message,
            httpStatus: $httpStatus,
            responseExcerpt: $excerpt,
            retryAfter: $retryAfter,
        );
    }

    public function isSuccessful(): bool
    {
        return $this->errorKind === null;
    }
}
