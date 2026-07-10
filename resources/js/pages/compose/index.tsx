import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, PenLine } from 'lucide-react';

import Composer from '@/components/compose/composer';
import { PostPageActions } from '@/components/posts/post-page-actions';
import { PublishedPostView } from '@/components/posts/published-post-view';
import { Button } from '@/components/ui/button';
import { firstLineTitle } from '@/lib/compose/composer-state';
import { dashboard } from '@/routes';
import { index as postsRoute } from '@/routes/posts';
import type { ComposePageProps } from '@/types/compose';

export default function ComposePage({
    post,
    accounts,
    sets,
    limits,
    savedMentions,
}: ComposePageProps) {
    const { features } = usePage().props;
    const title = firstLineTitle(post?.segments ?? ['']);
    const isPublished = Boolean(
        post && post.targets.some((t) => t.status === 'published'),
    );

    return (
        <>
            <Head title={title || 'Compose'} />
            <div className="mx-auto w-full max-w-7xl px-4 pt-6 pb-16 sm:px-6">
                <div className="sticky top-0 z-10 mb-5 flex items-center gap-2 border-b border-border bg-background/85 px-2 py-2 backdrop-blur-md">
                    <Button
                        nativeButton={false}
                        variant="ghost"
                        size="sm"
                        className="h-8 gap-1.5 px-2 text-muted-foreground hover:text-foreground"
                        render={<Link href={postsRoute().url} />}
                    >
                        <ArrowLeft className="size-4" />
                        Posts
                    </Button>
                    <div className="h-4 w-px bg-border" aria-hidden />
                    <div className="flex min-w-0 flex-1 items-center gap-1.5">
                        <PenLine
                            className="size-3.5 shrink-0 text-muted-foreground"
                            aria-hidden
                        />
                        <span className="truncate text-[13px] font-medium tracking-tight">
                            {title || 'Untitled draft'}
                        </span>
                    </div>
                    {post && <PostPageActions post={post} />}
                </div>

                {post && isPublished ? (
                    <PublishedPostView
                        post={post}
                        showMetrics={Boolean(features?.analytics)}
                    />
                ) : (
                    <Composer
                        post={post}
                        accounts={accounts}
                        sets={sets}
                        limits={limits}
                        initialSavedMentions={savedMentions}
                    />
                )}
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
