import { Head } from '@inertiajs/react';

import { ThemeControls } from '@/components/settings/theme-controls';
import { edit as editAppearance } from '@/routes/appearance';

export default function Appearance() {
    return (
        <>
            <Head title="Appearance" />
            <h1 className="sr-only">Appearance</h1>
            <ThemeControls />
        </>
    );
}

Appearance.layout = {
    breadcrumbs: [
        {
            title: 'Appearance',
            href: editAppearance(),
        },
    ],
};
