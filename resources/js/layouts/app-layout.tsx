import { FlashListener } from '@/components/common/flash-listener';
import FeedbackWidget from '@/components/feedback/feedback-widget';
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import type { BreadcrumbItem } from '@/types';

export default function AppLayout({
    breadcrumbs = [],
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
}) {
    return (
        <AppLayoutTemplate breadcrumbs={breadcrumbs}>
            <FlashListener />
            {children}
            <FeedbackWidget />
        </AppLayoutTemplate>
    );
}
