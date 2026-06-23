<?php

declare(strict_types=1);

return [
    'workspaces' => [
        'enabled' => env('WORKSPACES_ENABLED', true),
        'can_create_workspaces' => env('WORKSPACES_CAN_CREATE_WORKSPACE', true),
        'invitation_ttl_days' => (int) env('WORKSPACE_INVITATION_TTL_DAYS', 7),

        'roles' => [
            'owner' => [
                'permissions' => [
                    'workspace.read',
                    'workspace.update',
                    'workspace.delete',
                    'workspace.users.manage',
                    'workspace.settings.manage',
                    'workspace.billing.manage',
                    'workspace.accounts.manage',
                ],
            ],
            'admin' => [
                'permissions' => [
                    'workspace.read',
                    'workspace.update',
                    'workspace.users.manage',
                    'workspace.settings.manage',
                    'workspace.billing.manage',
                    'workspace.accounts.manage',
                ],
            ],
            'member' => [
                'permissions' => [
                    'workspace.read',
                ],
            ],
        ],
    ],

    'auth' => [
        'socialite' => [
            'enabled' => env('SOCIALITE_ENABLED', false),
            /** @var list<string> */
            'providers' => array_values(array_unique(array_filter(
                array_map(
                    fn (string $provider): string => mb_strtolower(trim($provider)),
                    explode(',', (string) env('SOCIALITE_PROVIDERS', 'google')),
                ),
            ))),
        ],
    ],
];
