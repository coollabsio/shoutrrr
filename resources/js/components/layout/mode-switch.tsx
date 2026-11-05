import { router, usePage } from '@inertiajs/react';

import UiModeController from '@/actions/App/Http/Controllers/UiModeController';
import { Bot, PenLine } from '@/components/ui/icons';
import {
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { resolveUiMode, type UiMode } from '@/lib/ui-mode';
import { dashboard } from '@/routes';
import { home as agenticHome } from '@/routes/agentic';

const HOME: Record<UiMode, () => { url: string }> = {
    agentic: agenticHome,
    classic: dashboard,
};

const modes: {
    value: UiMode;
    title: string;
    hint: string;
    icon: typeof Bot;
}[] = [
    {
        value: 'agentic',
        title: 'Agentic',
        hint: 'Agentic',
        icon: Bot,
    },
    {
        value: 'classic',
        title: 'Classic',
        hint: 'Classic',
        icon: PenLine,
    },
];

export function ModeSwitch() {
    const { auth } = usePage().props;
    const current = resolveUiMode(auth.user?.ui_mode);

    function select(next: UiMode) {
        // The current mode acts as a link to its home, so it is a way back from
        // Accounts, Sync, or Settings.
        if (next === current) {
            router.visit(HOME[next]().url);

            return;
        }

        router.put(
            UiModeController.update.url(),
            { mode: next },
            { preserveScroll: true },
        );
    }

    return (
        <SidebarGroup>
            <SidebarGroupLabel>Mode</SidebarGroupLabel>
            <SidebarGroupContent>
                <SidebarMenu role="radiogroup" aria-label="Product mode">
                    {modes.map((item) => {
                        const selected = current === item.value;

                        return (
                            <SidebarMenuItem key={item.value}>
                                <SidebarMenuButton
                                    type="button"
                                    tooltip={item.hint}
                                    isActive={selected}
                                    aria-checked={selected}
                                    role="radio"
                                    onClick={() => select(item.value)}
                                >
                                    <item.icon aria-hidden="true" />
                                    <span>{item.title}</span>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        );
                    })}
                </SidebarMenu>
            </SidebarGroupContent>
        </SidebarGroup>
    );
}
