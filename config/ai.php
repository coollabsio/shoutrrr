<?php

declare(strict_types=1);

return [
    // Default provider/model used when the instance settings leave them blank.
    // The active key is supplied at runtime from instance settings, not env.
    'provider' => env('AI_PROVIDER', 'anthropic'),
    'model' => env('AI_MODEL', 'claude-sonnet-4-5'),
];
