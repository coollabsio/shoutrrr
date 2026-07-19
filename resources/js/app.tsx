import { createInertiaApp } from '@inertiajs/react';

import { ConfirmProvider } from '@/components/common/confirm-dialog';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import { installDiagnostics } from '@/lib/diagnostics-collector';
import { initSentry } from '@/lib/sentry';

// Initialize error/performance monitoring before the app renders so early
// failures are captured. No-op unless a DSN was injected by the server.
initSentry();

// Start capturing console/network/navigation breadcrumbs as early as possible
// so the feedback widget can attach the events leading up to a report.
installDiagnostics();
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import InstanceSettingsLayout from '@/layouts/settings/instance-layout';
import SettingsLayout from '@/layouts/settings/layout';
import WorkspaceSettingsLayout from '@/layouts/settings/workspace-layout';

const appName = import.meta.env.VITE_APP_NAME || 'Shoutrrr';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            // Public, unauthenticated share viewer — no app shell/sidebar.
            case name.startsWith('share/'):
                return null;
            case name === 'error':
                return null;
            // Public MCP endpoint landing page — humans who hit /mcp in a
            // browser. No app shell/sidebar (no authenticated shared props).
            case name === 'mcp/landing':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name === 'settings/instance' ||
                name === 'settings/instance-polling' ||
                name === 'settings/instance-platforms' ||
                name === 'settings/instance-usage' ||
                name === 'settings/instance-admins':
                return [AppLayout, InstanceSettingsLayout];
            case name.startsWith('settings/workspace'):
                return [AppLayout, WorkspaceSettingsLayout];
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delay={0}>
                <ConfirmProvider>{app}</ConfirmProvider>
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: 'var(--primary)',
    },
});

// This will set light / dark mode on load...
initializeTheme();
