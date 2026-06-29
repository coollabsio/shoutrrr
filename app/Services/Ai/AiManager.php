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

        $provider = $this->settings->aiProvider();

        // The active key lives in instance settings (DB), not env, so inject it
        // into Prism's provider config for this request.
        config(['prism.providers.'.$provider.'.api_key' => $this->settings->aiApiKey()]);

        return Prism::text()->using($provider, $this->settings->aiModel());
    }
}
