import { Head, Link } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { PostRow, type PostRowData } from '@/pages/posts/post-row';
import { dashboard } from '@/routes';

type Props = { posts: PostRowData[] };

export default function PostsIndex({ posts }: Props) {
    return (
        <>
            <Head title="Posts" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-semibold">Posts</h1>
                        <p className="text-sm text-muted-foreground">
                            Your drafts and scheduled posts.
                        </p>
                    </div>
                    <Button asChild>
                        <Link href={dashboard().url}>New post</Link>
                    </Button>
                </div>

                {posts.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 py-12">
                        <p className="text-center text-sm text-muted-foreground">
                            No posts yet. Start composing.
                        </p>
                        <Button asChild variant="outline" size="sm">
                            <Link href={dashboard().url}>New post</Link>
                        </Button>
                    </div>
                ) : (
                    <div className="rounded-xl border border-border">
                        {posts.map((post) => (
                            <PostRow key={post.id} post={post} />
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

PostsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Posts',
            href: '/posts',
        },
    ],
};
