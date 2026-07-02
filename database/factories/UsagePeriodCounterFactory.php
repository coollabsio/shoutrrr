<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Platform;
use App\Enums\UsageCategory;
use App\Models\UsagePeriodCounter;
use App\Models\Workspace;
use App\Support\UsageOperation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/** @extends Factory<UsagePeriodCounter> */
class UsagePeriodCounterFactory extends Factory
{
    protected $model = UsagePeriodCounter::class;

    public function definition(): array
    {
        $now = Date::now();

        return [
            'workspace_id' => Workspace::factory(),
            'period_start' => $now->copy()->startOfMonth()->toDateString(),
            'period_end' => $now->copy()->endOfMonth()->toDateString(),
            'category' => UsageCategory::Publish->value,
            'platform' => Platform::X->value,
            'operation' => UsageOperation::POST,
            'event_count' => 0,
            'total_quota' => 0,
        ];
    }
}
