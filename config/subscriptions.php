<?php

return [
    'enabled' => ! (bool) env('SELF_HOSTED', true),
    'stripe_price_id' => env('STRIPE_SUBSCRIPTION_PRICE_ID', 'price_shoutrrr_monthly_test'),
    'monthly_price_cents' => (int) env('SHOUTRRR_SUBSCRIPTION_MONTHLY_CENTS', 1000),
    'monthly_x_budget_cents' => (int) env('SHOUTRRR_X_MONTHLY_BUDGET_CENTS', 500),

    // Maximum concurrent sync pipelines per workspace (inert unless enabled above).
    'max_sync_pipelines' => (int) env('SUBSCRIPTIONS_MAX_SYNC_PIPELINES', 3),

    // Maximum native tracked accounts per workspace (inert unless enabled above).
    'max_native_tracked' => (int) env('SUBSCRIPTIONS_MAX_NATIVE_TRACKED', 3),
];
