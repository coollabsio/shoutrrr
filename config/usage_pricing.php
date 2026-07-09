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
                'post_create' => ['label' => 'Post create', 'unit_cost_usd' => 0.015],
                'post_create_with_url' => ['label' => 'Post create (with URL)', 'unit_cost_usd' => 0.200],
                'content_create' => ['label' => 'Content create', 'unit_cost_usd' => 0.010],
                'analytics_read' => ['label' => 'Analytics read', 'unit_cost_usd' => 0.005],
                'media_metadata' => ['label' => 'Media metadata', 'unit_cost_usd' => 0.005],
                'user_interaction_create' => ['label' => 'User interaction create', 'unit_cost_usd' => 0.015],
                'interaction_delete' => ['label' => 'Interaction delete', 'unit_cost_usd' => 0.010],
            ],
            'operations' => [
                UsageOperation::POST => 'post_create',
                UsageOperation::POST_WITH_URL => 'post_create_with_url',
                UsageOperation::DELETE => 'interaction_delete',
                UsageOperation::METRICS_FETCH_POST => 'analytics_read',
                UsageOperation::METRICS_FETCH_ACCOUNT => 'analytics_read',
                UsageOperation::REPLIES_FETCH => 'posts_read',
                UsageOperation::REPLY_SEND => 'user_interaction_create',
                UsageOperation::REPLY_LIKE => 'user_interaction_create',
                UsageOperation::REPLY_UNLIKE => 'user_interaction_create',
                UsageOperation::REPLY_DELETE => 'interaction_delete',
                UsageOperation::MEDIA_UPLOAD => 'content_create',
                UsageOperation::MEDIA_STATUS_POLL => 'media_metadata',
            ],
        ],
    ],
];
