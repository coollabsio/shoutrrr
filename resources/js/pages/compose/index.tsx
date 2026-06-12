import { Head, Link } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { index as postsRoute } from '@/routes/posts';

import Composer from './Composer';
import { firstLineTitle } from './composer-state';
import type { ComposePageProps } from './types';

export default function ComposePage({
    post,
    accounts,
    sets,
    limits,
}: ComposePageProps) {
    const title = firstLineTitle(post?.base_text ?? '');

    return (
        <>
            <Head title="Compose" />
            <div className="mx-auto w-full max-w-[820px] px-4 pt-6 pb-16 sm:px-6">
                <div className="sticky top-0 z-10 mb-5 flex items-center justify-between gap-3 border-b border-border bg-background/85 px-3 py-2 backdrop-blur-md">
                    <Button asChild variant="ghost" size="sm">
                        <Link href={postsRoute().url}>← Back to drafts</Link>
                    </Button>
                    <div className="truncate text-[13px] tracking-tight text-muted-foreground">
                        {title || 'Untitled draft'}
                    </div>
                    <div className="w-24" />
                </div>

                <Composer
                    post={post}
                    accounts={accounts}
                    sets={sets}
                    limits={limits}
                />
            </div>
        </>
    );
}

ComposePage.layout = {
    breadcrumbs: [
        {
            title: 'Compose',
            href: dashboard().url,
        },
    ],
};
