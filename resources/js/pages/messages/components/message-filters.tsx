import { router } from '@inertiajs/react';

import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { index as messagesRoute } from '@/routes/messages';

import type { MessagesFilters } from '../types';

type Props = {
    filters: MessagesFilters;
};

export function MessageFilters({ filters }: Props) {
    function update(patch: Partial<MessagesFilters>) {
        const next = { ...filters, ...patch };
        router.get(messagesRoute().url, next as Record<string, boolean>, {
            preserveState: true,
            preserveScroll: true,
            only: ['conversations', 'filters'],
            reset: ['conversations'],
            replace: true,
        });
    }

    return (
        <div className="flex flex-wrap items-center gap-2 border-b px-3 py-2.5">
            <ToggleGroup
                value={[filters.archived ? 'archived' : 'all']}
                onValueChange={(value) => {
                    const v = value[0];
                    if (v) {
                        update({ archived: v === 'archived' });
                    }
                }}
                variant="outline"
                size="sm"
            >
                <ToggleGroupItem value="all" className="px-3 text-xs">
                    All
                </ToggleGroupItem>
                <ToggleGroupItem value="archived" className="px-3 text-xs">
                    Archived
                </ToggleGroupItem>
            </ToggleGroup>
        </div>
    );
}
