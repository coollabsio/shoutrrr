<?php

return [
    'enabled' => ! (bool) env('SELF_HOSTED', true),
    'stripe_price_id' => env('STRIPE_SUBSCRIPTION_PRICE_ID', 'price_shoutrrr_monthly_test'),
    'monthly_price_cents' => (int) env('SHOUTRRR_SUBSCRIPTION_MONTHLY_CENTS', 1000),
    'monthly_x_budget_cents' => (int) env('SHOUTRRR_X_MONTHLY_BUDGET_CENTS', 500),
];
