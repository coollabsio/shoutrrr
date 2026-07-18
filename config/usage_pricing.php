<?php

use App\Support\UsageOperation;

return [
    'source_url' => 'https://developer.x.com/#pricing',

    'platforms' => [
        'x' => [
            'label' => 'X API',
            'currency' => 'USD',
            'resources' => [
                'posts_read' => ['label' => 'Posts read', 'unit_cost_usd' => 0.005],
                'user_read' => ['label' => 'User read', 'unit_cost_usd' => 0.010],
                'owned_read' => ['label' => 'Owned read (your own account/posts)', 'unit_cost_usd' => 0.001],
                'post_create' => ['label' => 'Post create', 'unit_cost_usd' => 0.015],
                'post_create_with_url' => ['label' => 'Post create (with URL)', 'unit_cost_usd' => 0.200],
            ],
            'operations' => [
                // Writes — billed per request.
                UsageOperation::POST => 'post_create',
                UsageOperation::POST_WITH_URL => 'post_create_with_url',
                // Reads — billed per object returned, deduped per day (see UsageReadDedup).
                UsageOperation::REPLIES_FETCH => 'posts_read',
                UsageOperation::METRICS_FETCH_POST => 'owned_read',
                UsageOperation::METRICS_FETCH_ACCOUNT => 'owned_read',
                // Intentionally unmapped (X does not meter these): MEDIA_UPLOAD,
                // MEDIA_STATUS_POLL, DELETE, REPLY_DELETE, REPLY_LIKE, REPLY_UNLIKE,
                // REPLY_SEND, TOKEN_REFRESH. They are still recorded as usage events
                // for observability but carry no cost weight.
            ],
        ],
    ],
];
