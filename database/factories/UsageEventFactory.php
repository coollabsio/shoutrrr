<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Platform;
use App\Enums\UsageCategory;
use App\Models\UsageEvent;
use App\Models\Workspace;
use App\Support\UsageOperation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/** @extends Factory<UsageEvent> */
class UsageEventFactory extends Factory
{
    protected $model = UsageEvent::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'category' => UsageCategory::Publish->value,
            'operation' => UsageOperation::POST,
            'platform' => Platform::X->value,
            'quota_weight' => 1,
            'succeeded' => true,
            'meta' => null,
            'occurred_at' => Date::now(),
        ];
    }
}
