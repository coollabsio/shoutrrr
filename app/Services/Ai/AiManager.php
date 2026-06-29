<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Support\InstanceSettings;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\PendingRequest;

class AiManager
{
    public function __construct(private InstanceSettings $settings) {}

    public function textRequest(): PendingRequest
    {
        abort_unless($this->settings->aiConfigured(), 404);

        return Prism::text()->using(
            $this->settings->aiProvider(),
            $this->settings->aiModel(),
            ['api_key' => $this->settings->aiApiKey()],
        );
    }
}
