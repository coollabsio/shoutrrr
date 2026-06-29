import { router } from '@inertiajs/react';

import { switchMethod } from '@/routes/workspaces';

type SwitchWorkspaceOptions = {
    onFinish?: () => void;
};

export function switchWorkspace(
    workspaceId: string,
    options: SwitchWorkspaceOptions = {},
) {
    router.post(
        switchMethod.url(),
        { workspace_id: workspaceId },
        {
            preserveState: false,
            onFinish: options.onFinish,
        },
    );
}
