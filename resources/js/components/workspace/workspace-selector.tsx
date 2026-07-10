import { usePage } from '@inertiajs/react';
import { Check, ChevronsUpDown, Loader2, Plus, Users } from 'lucide-react';
import { useState } from 'react';

import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { CreateWorkspaceDialog } from '@/components/workspace/create-workspace-dialog';
import { useInitials } from '@/hooks/use-initials';
import { useIsMobile } from '@/hooks/use-mobile';
import { switchWorkspace } from '@/lib/workspaces/switch-workspace';

export function WorkspaceSelector() {
    const { workspaces } = usePage().props;
    const { state } = useSidebar();
    const isMobile = useIsMobile();
    const getInitials = useInitials();
    const [loading, setLoading] = useState(false);
    const [dialogOpen, setDialogOpen] = useState(false);

    if (!workspaces.enabled || workspaces.all.length === 0) {
        return null;
    }

    const current = workspaces.current;

    const handleSwitch = (id: string) => {
        if (id === current?.id || loading) {
            return;
        }

        setLoading(true);
        switchWorkspace(id, { onFinish: () => setLoading(false) });
    };

    return (
        <>
            <SidebarMenu>
                <SidebarMenuItem>
                    <DropdownMenu>
                        <DropdownMenuTrigger
                            render={
                                <SidebarMenuButton
                                    size="lg"
                                    disabled={loading}
                                    className="data-[popup-open]:bg-sidebar-accent"
                                />
                            }
                        >
                            <div className="flex aspect-square size-8 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-sidebar-accent text-[12.5px] font-semibold text-sidebar-accent-foreground">
                                {current?.logo ? (
                                    <img
                                        alt={current.name}
                                        src={current.logo}
                                        className="size-8 rounded-lg object-cover"
                                    />
                                ) : current?.name ? (
                                    getInitials(current.name)
                                ) : (
                                    <Users className="size-4" />
                                )}
                            </div>
                            <div className="grid flex-1 text-left text-sm leading-tight">
                                <span className="text-[10px] font-medium tracking-wide text-muted-foreground uppercase">
                                    Workspace
                                </span>
                                <span className="truncate font-medium">
                                    {current?.name ?? 'Select workspace'}
                                </span>
                            </div>
                            {loading ? (
                                <Loader2 className="ml-auto size-4 animate-spin" />
                            ) : (
                                <ChevronsUpDown className="ml-auto size-4" />
                            )}
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            align="start"
                            side={
                                isMobile
                                    ? 'bottom'
                                    : state === 'collapsed'
                                      ? 'right'
                                      : 'bottom'
                            }
                            className="w-(--anchor-width) min-w-56 rounded-lg"
                        >
                            {workspaces.all.map((workspace) => (
                                <DropdownMenuItem
                                    key={workspace.id}
                                    disabled={loading}
                                    onClick={() => handleSwitch(workspace.id)}
                                    className="cursor-pointer gap-2"
                                >
                                    <img
                                        alt={workspace.name}
                                        src={workspace.logo}
                                        className="size-4 rounded"
                                    />
                                    <span className="truncate">
                                        {workspace.name}
                                    </span>
                                    {current?.id === workspace.id && (
                                        <Check className="ml-auto size-4" />
                                    )}
                                </DropdownMenuItem>
                            ))}

                            {workspaces.canCreateWorkspaces && (
                                <DropdownMenuItem
                                    onClick={() => setDialogOpen(true)}
                                    className="cursor-pointer gap-2"
                                >
                                    <Plus className="size-4" />
                                    Create workspace
                                </DropdownMenuItem>
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>
                </SidebarMenuItem>
            </SidebarMenu>

            <CreateWorkspaceDialog
                open={dialogOpen}
                onOpenChange={setDialogOpen}
            />
        </>
    );
}
