import { useHttp } from '@inertiajs/react';
import type { Dispatch, SetStateAction } from 'react';

import WorkspaceMentionController from '@/actions/App/Http/Controllers/WorkspaceMentionController';
import { failureToasts, requireResponse } from '@/lib/http/errors';
import type { MentionPlaceholder, WorkspaceMention } from '@/types/compose';

/**
 * Saves a mention to the workspace library. Rejects when the save fails, so the
 * mention picker stays open; the user has already been told why.
 */
export function useSaveMention(
    setSavedMentions: Dispatch<SetStateAction<WorkspaceMention[]>>,
) {
    const http = useHttp<Record<string, never>, { mention: WorkspaceMention }>(
        {},
    );

    async function saveMention(mention: MentionPlaceholder): Promise<void> {
        http.transform(() => ({
            name: mention.label,
            handles: mention.handles,
        }));
        const response = requireResponse(
            await http.post(
                WorkspaceMentionController.store().url,
                failureToasts('Could not save the mention.'),
            ),
        );

        setSavedMentions((current) => {
            const others = current.filter(
                (item) =>
                    item.id !== response.mention.id &&
                    item.name !== response.mention.name,
            );

            return [...others, response.mention].sort((left, right) =>
                left.name.localeCompare(right.name),
            );
        });
    }

    return { saveMention, processing: http.processing };
}
