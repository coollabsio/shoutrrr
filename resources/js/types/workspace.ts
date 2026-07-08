export type WorkspaceRole = 'owner' | 'admin' | 'member';

export type WorkspaceSummary = {
    id: string;
    name: string;
    role: WorkspaceRole;
    logo: string;
};

export type CurrentWorkspace = WorkspaceSummary & {
    permissions: string[];
    timezone?: string;
};

export type WorkspacesData = {
    enabled: boolean;
    all: WorkspaceSummary[];
    current: CurrentWorkspace | null;
    canCreateWorkspaces: boolean;
};

export type FlashData = {
    success: string | null;
    error: string | null;
    plainTextApiKey: string | null;
};
