<?php

declare(strict_types=1);

return [
    'workspaces' => [
        'enabled' => env('WORKSPACES_ENABLED', true),
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

    /*
    |--------------------------------------------------------------------------
    | Public legal pages
    |--------------------------------------------------------------------------
    |
    | Configuration for the workspace-owned Terms & Privacy pages served
    | publicly at `/{slug}/terms` and `/{slug}/privacy`.
    |
    */
    'legal' => [
        // Maximum length (characters) accepted for a single legal document's
        // Markdown source. Generous enough for real policies while bounding the
        // stored payload.
        'max_body_length' => (int) env('LEGAL_PAGE_MAX_BODY_LENGTH', 50000),

        // Slugs a workspace may not claim, because they would collide with — or
        // impersonate — first-party, top-level application routes. The public
        // route already constrains its second segment to `terms|privacy`, so no
        // real route can be shadowed; this list is defence-in-depth against
        // confusing or phishing-friendly public URLs.
        'reserved_slugs' => [
            'about', 'account', 'accounts', 'admin', 'ai', 'analytics', 'api',
            'app', 'assets', 'auth', 'billing', 'build', 'command-search',
            'compose', 'connections', 'contact', 'dashboard', 'email',
            'engagement', 'favicon', 'forgot-password', 'health', 'help', 'home',
            'invitation', 'legal', 'login', 'logout', 'mcp', 'notifications',
            'oauth', 'onboarding', 'password', 'post', 'posts', 'privacy',
            'queue', 'register', 'reset-password', 'robots', 'settings', 'share',
            'sitemap', 'status', 'storage', 'support', 'terms', 'two-factor',
            'up', 'user', 'verify-email', 'workspace', 'workspace-invitations',
            'workspace-mentions', 'workspaces', 'www',
        ],
    ],
];
