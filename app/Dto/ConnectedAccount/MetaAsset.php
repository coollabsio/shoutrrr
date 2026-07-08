<?php

declare(strict_types=1);

namespace App\Dto\ConnectedAccount;

final readonly class MetaAsset
{
    public function __construct(
        public string $pageId,
        public string $pageName,
        public string $pageAccessToken,
        public ?string $igUserId = null,
        public ?string $igUsername = null,
        public ?string $igAvatarUrl = null,
    ) {}
}
