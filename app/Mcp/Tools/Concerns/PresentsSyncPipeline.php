<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Models\SyncPipeline;

trait PresentsSyncPipeline
{
    /**
     * @return array{id: string, name: string, enabled: bool, source_connected_account_id: string, destination_connected_account_ids: list<string>}
     */
    protected function pipelinePayload(SyncPipeline $pipeline): array
    {
        $pipeline->load('destinations:id');
        $destinationIds = [];

        foreach ($pipeline->destinations->pluck('id') as $id) {
            $destinationIds[] = (string) $id;
        }

        return [
            'id' => $pipeline->id,
            'name' => $pipeline->name,
            'enabled' => $pipeline->enabled,
            'source_connected_account_id' => $pipeline->source_connected_account_id,
            'destination_connected_account_ids' => $destinationIds,
        ];
    }
}
