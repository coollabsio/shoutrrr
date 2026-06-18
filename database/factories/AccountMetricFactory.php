<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AccountMetric;
use App\Models\ConnectedAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/** @extends Factory<AccountMetric> */
class AccountMetricFactory extends Factory
{
    public function definition(): array
    {
        return [
            'connected_account_id' => ConnectedAccount::factory(),
            'captured_at' => Date::now(),
            'followers' => $this->faker->numberBetween(0, 10000),
            'following' => $this->faker->numberBetween(0, 1000),
            'posts_count' => $this->faker->numberBetween(0, 500),
            'raw' => null,
        ];
    }
}
