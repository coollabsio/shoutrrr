<?php

declare(strict_types=1);

namespace App\Dto\Publishing;

use App\Enums\ErrorKind;

final readonly class PublishResult
{
    /**
     * @param  list<string>  $remoteIds
     * @param  array<string, mixed>|null  $remoteMetadata
     */
    public function __construct(
        public array $remoteIds,
        public ?ErrorKind $errorKind = null,
        public ?string $errorMessage = null,
        public ?int $httpStatus = null,
        public ?string $responseExcerpt = null,
        public ?int $retryAfter = null,
        public ?array $remoteMetadata = null,
        public bool $mayHaveCreatedRemote = false,
    ) {}

    /**
     * @param  list<string>  $remoteIds
     * @param  array<string, mixed>|null  $remoteMetadata
     */
    public static function success(array $remoteIds, ?array $remoteMetadata = null, ?int $httpStatus = null): self
    {
        return new self(remoteIds: $remoteIds, remoteMetadata: $remoteMetadata, httpStatus: $httpStatus);
    }

    public static function failure(ErrorKind $kind, string $message, ?int $httpStatus = null, ?string $excerpt = null, ?int $retryAfter = null, bool $mayHaveCreatedRemote = false): self
    {
        return new self(
            remoteIds: [],
            errorKind: $kind,
            errorMessage: $message,
            httpStatus: $httpStatus,
            responseExcerpt: $excerpt,
            retryAfter: $retryAfter,
            mayHaveCreatedRemote: $mayHaveCreatedRemote,
        );
    }

    public function isSuccessful(): bool
    {
        return $this->errorKind === null;
    }
}
