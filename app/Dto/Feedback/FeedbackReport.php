<?php

declare(strict_types=1);

namespace App\Dto\Feedback;

use App\Enums\FeedbackType;

class FeedbackReport
{
    public function __construct(
        public FeedbackType $type,
        public string $message,
        public string $url,
        public string $browser,
        public string $environment,
        public string $userName,
        public string $userEmail,
        public string $workspaceName,
        public string $workspaceId,
        public string $subscriptionStatus,
        public ?string $screenshotBytes = null,
        public ?string $diagnosticsJson = null,
    ) {}
}
