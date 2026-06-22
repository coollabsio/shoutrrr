import { router } from '@inertiajs/react';

import { switchMethod } from '@/routes/workspaces';

type SwitchWorkspaceOptions = {
    onFinish?: () => void;
};

export function switchWorkspace(
    workspaceId: string,
    options: SwitchWorkspaceOptions = {},
) {
    router.flushAll();
    router.post(
        switchMethod.url(),
        { workspace_id: workspaceId },
        {
            preserveState: false,
            onSuccess: () => router.flushAll(),
            onFinish: options.onFinish,
        },
    );
}
