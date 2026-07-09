<?php

declare(strict_types=1);

namespace App\Support;

class UsagePricing
{
    /**
     * @return array{resource: string, label: string, unit_cost_usd: float, estimated_cost_usd: float}|null
     */
    public function estimate(string $platform, string $operation, int $quota): ?array
    {
        $resource = config("usage_pricing.platforms.{$platform}.operations.{$operation}");

        if (! is_string($resource)) {
            return null;
        }

        $pricing = config("usage_pricing.platforms.{$platform}.resources.{$resource}");

        if (! is_array($pricing) || ! isset($pricing['unit_cost_usd'], $pricing['label'])) {
            return null;
        }

        $unitCost = (float) $pricing['unit_cost_usd'];

        return [
            'resource' => $resource,
            'label' => (string) $pricing['label'],
            'unit_cost_usd' => $unitCost,
            'estimated_cost_usd' => round($quota * $unitCost, 6),
        ];
    }

    public function costWeightMicrousd(string $platform, string $operation, int $quota): int
    {
        $estimate = $this->estimate($platform, $operation, $quota);

        if ($estimate === null) {
            return 0;
        }

        return (int) round($estimate['estimated_cost_usd'] * 1_000_000);
    }
}
