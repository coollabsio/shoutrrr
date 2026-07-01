<?php

declare(strict_types=1);

return [
    'self_hosted' => env('SELF_HOSTED', false),

    'defaults' => [
        'registrations_enabled' => env('INSTANCE_REGISTRATIONS_ENABLED', false),
        'workspace_creation_enabled' => env(
            'INSTANCE_WORKSPACE_CREATION_ENABLED',
            env('WORKSPACES_CAN_CREATE_WORKSPACE', true),
        ),
        'usage_tracking_enabled' => env('INSTANCE_USAGE_TRACKING_ENABLED', false),
    ],
];
