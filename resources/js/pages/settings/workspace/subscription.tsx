import { Head } from '@inertiajs/react';

import BillingController from '@/actions/App/Http/Controllers/BillingController';
import Heading from '@/components/common/heading';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

const usd = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
});

function csrfToken(): string {
    if (typeof document === 'undefined') {
        return '';
    }

    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

type Props = {
    subscribed: boolean;
    monthlyPrice: number;
    monthlyXPostLimit: number;
    monthlyXPostUsed: number;
    monthlyXPostRemaining: number;
    canManageSubscription: boolean;
};

export default function Subscription({
    subscribed,
    monthlyPrice,
    monthlyXPostLimit,
    monthlyXPostUsed,
    monthlyXPostRemaining,
    canManageSubscription,
}: Props) {
    const monthlyUsagePercent =
        monthlyXPostLimit > 0
            ? Math.min(100, (monthlyXPostUsed / monthlyXPostLimit) * 100)
            : 0;
    const remainingLabel =
        monthlyXPostRemaining === Number.MAX_SAFE_INTEGER ||
        monthlyXPostRemaining > monthlyXPostLimit
            ? 'Unlimited remaining'
            : `${monthlyXPostRemaining} remaining`;

    return (
        <>
            <Head title="Subscription" />

            <h1 className="sr-only">Subscription</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Subscription"
                    description="Manage publishing access for this workspace"
                />

                <Card>
                    <CardHeader>
                        <CardTitle>Shoutrrr Cloud</CardTitle>
                        <CardDescription>
                            Unlimited seats with monthly X publishing included.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        <div className="space-y-2">
                            <div className="text-3xl font-semibold">
                                {usd.format(monthlyPrice / 100)}
                                <span className="text-base font-normal text-muted-foreground">
                                    /month
                                </span>
                            </div>
                            <p className="text-sm text-muted-foreground">
                                Includes unlimited seats, unlimited publishes to
                                every other platform, and {monthlyXPostLimit}{' '}
                                X/Twitter publish requests each month.
                            </p>
                        </div>

                        <div className="rounded-2xl border bg-muted/30 p-4 text-sm">
                            <span className="font-medium">Status: </span>
                            {subscribed
                                ? 'Active subscription'
                                : 'Not subscribed'}
                        </div>

                        <div className="rounded-2xl border p-4">
                            <div className="flex items-start justify-between gap-4">
                                <div>
                                    <h2 className="text-sm font-medium">
                                        X posts this month
                                    </h2>
                                    <p className="text-sm text-muted-foreground">
                                        {remainingLabel}
                                    </p>
                                </div>
                                <div className="text-right text-sm">
                                    <span className="text-2xl font-semibold text-foreground">
                                        {monthlyXPostUsed}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {' '}
                                        / {monthlyXPostLimit}
                                    </span>
                                </div>
                            </div>
                            <div className="mt-4 h-2 overflow-hidden rounded-full bg-muted">
                                <div
                                    className="h-full rounded-full bg-primary transition-all"
                                    style={{
                                        width: `${monthlyUsagePercent}%`,
                                    }}
                                />
                            </div>
                        </div>

                        {canManageSubscription ? (
                            <form
                                action={BillingController.portal.url()}
                                method="post"
                            >
                                <input
                                    type="hidden"
                                    name="_token"
                                    defaultValue={csrfToken()}
                                />
                                <Button type="submit">
                                    Manage subscription
                                </Button>
                            </form>
                        ) : (
                            <form
                                action={BillingController.checkout.url()}
                                method="post"
                            >
                                <input
                                    type="hidden"
                                    name="_token"
                                    defaultValue={csrfToken()}
                                />
                                <Button type="submit">
                                    Subscribe to publish
                                </Button>
                            </form>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
