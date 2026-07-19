import { Head, Link, router, useHttp } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

import InstanceSettingsController from '@/actions/App/Http/Controllers/Settings/InstanceSettingsController';
import Heading from '@/components/common/heading';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';

import { WorkspaceQuotaEditor } from './instance-usage/workspace-quota-editor';

export type WorkspaceQuota = {
    kind: 'default' | 'custom' | 'unlimited';
    dollars: number | null;
};

type WorkspaceUsageRow = {
    id: string;
    name: string;
    x_estimated_cost_usd: number;
    x_previous_cost_usd: number;
    x_cost_delta_usd: number;
    quota: WorkspaceQuota;
    percent_used: number | null;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type WorkspaceUsagePaginator = {
    data: WorkspaceUsageRow[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

type PricingEstimate = {
    resource: string;
    label: string;
    unit_cost_usd: number;
    estimated_cost_usd: number;
};

type DrilldownCounter = {
    id: string;
    period_start: string;
    period_end: string;
    category: string;
    platform: string;
    operation: string;
    event_count: number;
    total_quota: number;
    pricing: PricingEstimate | null;
};

type DrilldownErrorEvent = {
    id: string;
    category: string;
    operation: string;
    platform: string;
    quota_weight: number;
    meta: Record<string, unknown> | null;
    occurred_at: string;
};

type DrilldownOwner = {
    name: string;
    email: string;
    avatar: string;
};

type Drilldown = {
    workspace: {
        id: string;
        name: string;
        is_initial: boolean;
        quota: WorkspaceQuota;
        owner: DrilldownOwner | null;
    };
    counters: DrilldownCounter[];
    error_events: DrilldownErrorEvent[];
} | null;

type Filters = {
    search: string | null;
    sort: 'spend' | 'name';
    workspace: string | null;
};

type XUsageApp = {
    app_id?: string;
    tweets_consumed?: number;
};

type XUsageDay = {
    date?: string;
    usage?: XUsageApp[];
};

type XUsageData = {
    cap_reset_day?: number;
    daily_client_app_usage?: XUsageDay[];
    daily_project_usage?: XUsageDay[] | { usage?: XUsageApp[] };
    project_cap?: number;
    project_id?: string;
    project_usage?: number;
};

type XUsageResponse = {
    data: XUsageData | null;
    fetched_at: string;
    source: string;
};

type Props = {
    filters: Filters;
    instance_summary: {
        workspace_count: number;
        x_estimated_cost_usd: number;
    };
    workspace_usage: WorkspaceUsagePaginator;
    pricing_source: string;
    pricing_currency: string;
    x_usage_available: boolean;
    drilldown?: Drilldown;
};

const sortItems: { value: Filters['sort']; label: string }[] = [
    { value: 'spend', label: 'Highest spend' },
    { value: 'name', label: 'Name (A–Z)' },
];

export function usageQuery(filters: Filters) {
    return {
        ...(filters.search ? { search: filters.search } : {}),
        ...(filters.sort !== 'spend' ? { sort: filters.sort } : {}),
        ...(filters.workspace ? { workspace: filters.workspace } : {}),
    };
}

export function xUsageTotal(data: XUsageData | null) {
    if (!data) {
        return 0;
    }

    if (typeof data.project_usage === 'number') {
        return data.project_usage;
    }

    const dailyUsage = Array.isArray(data.daily_project_usage)
        ? data.daily_project_usage
        : [];

    return dailyUsage.reduce(
        (total, day) =>
            total +
            (day.usage ?? []).reduce(
                (dayTotal, app) => dayTotal + (app.tweets_consumed ?? 0),
                0,
            ),
        0,
    );
}

export function canFetchXUsage(isConfigured: boolean, isProcessing: boolean) {
    return isConfigured && !isProcessing;
}

export default function InstanceUsage({
    filters,
    instance_summary,
    workspace_usage,
    pricing_source,
    pricing_currency,
    x_usage_available,
    drilldown,
}: Props) {
    const xUsageHttp = useHttp<Record<string, never>, XUsageResponse>({});
    const [xUsage, setXUsage] = useState<XUsageResponse | null>(null);
    const [xUsageError, setXUsageError] = useState<string | null>(null);

    const [localSearch, setLocalSearch] = useState(filters.search ?? '');
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    // Keep the search box in sync when filters change from outside (e.g. back/forward nav).
    useEffect(() => {
        setLocalSearch(filters.search ?? '');
    }, [filters.search]);

    function navigate(next: Partial<Filters>, options?: { only?: string[] }) {
        const nextFilters: Filters = {
            search: Object.hasOwn(next, 'search')
                ? (next.search ?? null)
                : filters.search,
            sort: Object.hasOwn(next, 'sort')
                ? (next.sort ?? 'spend')
                : filters.sort,
            workspace: Object.hasOwn(next, 'workspace')
                ? (next.workspace ?? null)
                : filters.workspace,
        };

        router.get(
            InstanceSettingsController.usage({
                query: usageQuery(nextFilters),
            }).url,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
                ...(options?.only ? { only: options.only } : {}),
            },
        );
    }

    function handleSearchChange(value: string) {
        setLocalSearch(value);
        if (debounceRef.current) {
            clearTimeout(debounceRef.current);
        }
        debounceRef.current = setTimeout(() => {
            navigate({ search: value || null });
        }, 250);
    }

    function openDrilldown(workspaceId: string) {
        navigate(
            { workspace: workspaceId },
            { only: ['drilldown', 'filters'] },
        );
    }

    function closeDrilldown() {
        navigate({ workspace: null });
    }

    function fetchXUsage() {
        if (!canFetchXUsage(x_usage_available, xUsageHttp.processing)) {
            return;
        }

        setXUsageError(null);

        void xUsageHttp.get(InstanceSettingsController.xUsage().url, {
            onSuccess: (response) => {
                setXUsage(response);
            },
            onError: (errors) => {
                setXUsageError(
                    Object.values(errors)[0] ?? 'Unable to fetch X API usage.',
                );
            },
        });
    }

    return (
        <>
            <Head title="Instance usage" />

            <div className="space-y-8">
                <div className="space-y-4">
                    <Heading
                        variant="small"
                        title="Usage"
                        description="Review tracked platform API usage by workspace. Estimates use mapped X API pricing where available."
                    />
                    <p className="text-xs text-muted-foreground">
                        Pricing estimates are informational and based on the{' '}
                        <a
                            href={pricing_source}
                            target="_blank"
                            rel="noreferrer"
                            className="underline underline-offset-4"
                        >
                            X API pricing page
                        </a>
                        . Non-X platforms and unmapped operations show no cost.
                    </p>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <UsageStat
                            label="Workspaces"
                            value={instance_summary.workspace_count.toLocaleString()}
                        />
                        <UsageStat
                            label="Est. X spend this period"
                            value={formatMoney(
                                instance_summary.x_estimated_cost_usd,
                                pricing_currency,
                            )}
                        />
                    </div>
                </div>

                <section className="rounded-md border p-4">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div className="space-y-1">
                            <h2 className="text-sm font-medium">X API usage</h2>
                            <p className="text-sm text-muted-foreground">
                                Fetch daily Post consumption from the X Usage
                                API for the configured developer app.
                            </p>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={
                                !canFetchXUsage(
                                    x_usage_available,
                                    xUsageHttp.processing,
                                )
                            }
                            onClick={fetchXUsage}
                        >
                            {xUsageHttp.processing
                                ? 'Fetching…'
                                : 'Fetch X usage'}
                        </Button>
                    </div>

                    {!x_usage_available && (
                        <p className="mt-4 text-sm text-muted-foreground">
                            Configure X_BEARER_TOKEN to enable fetching X API
                            usage.
                        </p>
                    )}

                    {xUsageError && (
                        <p className="mt-4 text-sm text-destructive">
                            {xUsageError}
                        </p>
                    )}

                    {xUsage && (
                        <div className="mt-4 space-y-3">
                            <XCapacityMeter
                                consumed={xUsageTotal(xUsage.data)}
                                cap={xUsage.data?.project_cap ?? null}
                            />
                            <p className="text-xs text-muted-foreground">
                                {typeof xUsage.data?.cap_reset_day ===
                                    'number' &&
                                    `Cap resets in ${xUsage.data.cap_reset_day} days. `}
                                Fetched {formatDate(xUsage.fetched_at)} from{' '}
                                <a
                                    href={xUsage.source}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="underline underline-offset-4"
                                >
                                    {xUsage.source}
                                </a>
                                .
                            </p>
                        </div>
                    )}
                </section>

                <section className="space-y-4">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div className="w-full sm:max-w-xs">
                            <Input
                                placeholder="Search workspaces…"
                                value={localSearch}
                                onChange={(e) =>
                                    handleSearchChange(e.target.value)
                                }
                            />
                        </div>
                        <Select
                            items={sortItems}
                            value={filters.sort}
                            onValueChange={(sort) =>
                                navigate({ sort: sort as Filters['sort'] })
                            }
                        >
                            <SelectTrigger className="w-full sm:w-48">
                                <SelectValue placeholder="Sort" />
                            </SelectTrigger>
                            <SelectContent>
                                {sortItems.map((item) => (
                                    <SelectItem
                                        key={item.value}
                                        value={item.value}
                                    >
                                        {item.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="min-w-0 rounded-md border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Workspace</TableHead>
                                    <TableHead>Est. spend</TableHead>
                                    <TableHead>Quota</TableHead>
                                    <TableHead>% used</TableHead>
                                    <TableHead>Change</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {workspace_usage.data.length > 0 ? (
                                    workspace_usage.data.map((row) => (
                                        <TableRow key={row.id}>
                                            <TableCell>
                                                <button
                                                    type="button"
                                                    className="font-medium underline-offset-4 hover:underline"
                                                    onClick={() =>
                                                        openDrilldown(row.id)
                                                    }
                                                >
                                                    {row.name}
                                                </button>
                                            </TableCell>
                                            <TableCell>
                                                {formatMoney(
                                                    row.x_estimated_cost_usd,
                                                    pricing_currency,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <QuotaBadge
                                                    quota={row.quota}
                                                    currency={pricing_currency}
                                                />
                                            </TableCell>
                                            <TableCell>
                                                {row.percent_used === null ? (
                                                    <span className="text-sm text-muted-foreground">
                                                        —
                                                    </span>
                                                ) : (
                                                    <PercentUsedMeter
                                                        percent={
                                                            row.percent_used
                                                        }
                                                    />
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <CostDeltaBadge
                                                    delta={row.x_cost_delta_usd}
                                                    currency={pricing_currency}
                                                />
                                            </TableCell>
                                        </TableRow>
                                    ))
                                ) : (
                                    <TableRow>
                                        <TableCell
                                            colSpan={5}
                                            className="py-6 text-center text-sm text-muted-foreground"
                                        >
                                            No workspaces match your search.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </div>

                    {workspace_usage.links.length > 3 && (
                        <div className="flex flex-wrap items-center gap-1">
                            {workspace_usage.links.map((link, index) =>
                                link.url === null ? (
                                    <span
                                        key={index}
                                        className="rounded-md px-2.5 py-1 text-sm text-muted-foreground/60"
                                        dangerouslySetInnerHTML={{
                                            __html: link.label,
                                        }}
                                    />
                                ) : (
                                    <Link
                                        key={index}
                                        href={link.url}
                                        preserveScroll
                                        preserveState
                                        className={cn(
                                            'rounded-md px-2.5 py-1 text-sm',
                                            link.active
                                                ? 'bg-foreground text-background'
                                                : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                                        )}
                                        dangerouslySetInnerHTML={{
                                            __html: link.label,
                                        }}
                                    />
                                ),
                            )}
                        </div>
                    )}
                </section>
            </div>

            <Sheet
                open={filters.workspace !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        closeDrilldown();
                    }
                }}
            >
                <SheetContent
                    side="right"
                    className="w-full gap-0 overflow-y-auto sm:max-w-xl"
                >
                    <SheetHeader>
                        <SheetTitle>
                            {drilldown?.workspace.name ?? 'Workspace'}
                        </SheetTitle>
                    </SheetHeader>

                    <div className="flex-1 space-y-8 px-6 pb-6">
                        {drilldown ? (
                            <>
                                <WorkspaceOwner
                                    owner={drilldown.workspace.owner}
                                />

                                <WorkspaceQuotaEditor
                                    workspaceId={drilldown.workspace.id}
                                    quota={drilldown.workspace.quota}
                                    locked={drilldown.workspace.is_initial}
                                />

                                <UsageTable
                                    title="Monthly counters"
                                    description="Aggregated successful usage for each recorded period."
                                    empty="No usage counters recorded yet."
                                    columns={[
                                        'Period',
                                        'Category',
                                        'Platform',
                                        'Operation',
                                        'Events',
                                        'Quota',
                                        'Est. cost',
                                        'Pricing basis',
                                    ]}
                                >
                                    {drilldown.counters.map((counter) => (
                                        <TableRow key={counter.id}>
                                            <TableCell>
                                                {counter.period_start} →{' '}
                                                {counter.period_end}
                                            </TableCell>
                                            <TableCell>
                                                {formatLabel(counter.category)}
                                            </TableCell>
                                            <TableCell>
                                                {formatPlatform(
                                                    counter.platform,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {formatLabel(counter.operation)}
                                            </TableCell>
                                            <TableCell>
                                                {counter.event_count}
                                            </TableCell>
                                            <TableCell>
                                                {counter.total_quota}
                                            </TableCell>
                                            <TableCell>
                                                {counter.pricing
                                                    ? formatMoney(
                                                          counter.pricing
                                                              .estimated_cost_usd,
                                                          pricing_currency,
                                                      )
                                                    : '—'}
                                            </TableCell>
                                            <TableCell>
                                                {counter.pricing
                                                    ? `${counter.pricing.label} @ ${formatMoney(
                                                          counter.pricing
                                                              .unit_cost_usd,
                                                          pricing_currency,
                                                      )}`
                                                    : 'Unmapped'}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </UsageTable>

                                <UsageTable
                                    title="Error events"
                                    description="Latest failed usage events for debugging platform/API problems."
                                    empty="No failed usage events recorded yet."
                                    columns={[
                                        'When',
                                        'Category',
                                        'Platform',
                                        'Operation',
                                        'Quota',
                                        'Error meta',
                                    ]}
                                >
                                    {drilldown.error_events.map((event) => (
                                        <TableRow key={event.id}>
                                            <TableCell>
                                                {formatDate(event.occurred_at)}
                                            </TableCell>
                                            <TableCell>
                                                {formatLabel(event.category)}
                                            </TableCell>
                                            <TableCell>
                                                {formatPlatform(event.platform)}
                                            </TableCell>
                                            <TableCell>
                                                {formatLabel(event.operation)}
                                            </TableCell>
                                            <TableCell>
                                                {event.quota_weight}
                                            </TableCell>
                                            <TableCell className="max-w-64 truncate font-mono text-xs text-muted-foreground">
                                                {event.meta
                                                    ? JSON.stringify(event.meta)
                                                    : '—'}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </UsageTable>
                            </>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                Loading workspace details…
                            </p>
                        )}
                    </div>
                </SheetContent>
            </Sheet>
        </>
    );
}

function QuotaBadge({
    quota,
    currency,
}: {
    quota: WorkspaceQuota;
    currency: string;
}) {
    if (quota.kind === 'unlimited') {
        return <Badge variant="success">Unlimited</Badge>;
    }

    if (quota.kind === 'custom') {
        return (
            <Badge variant="outline">
                {formatMoney(quota.dollars ?? 0, currency)}/mo
            </Badge>
        );
    }

    return (
        <Badge variant="outline">
            Default {formatMoney(quota.dollars ?? 0, currency)}/mo
        </Badge>
    );
}

function XCapacityMeter({
    consumed,
    cap,
}: {
    consumed: number;
    cap: number | null;
}) {
    const percent = cap ? Math.min(100, (consumed / cap) * 100) : 0;
    const isWarning = percent > 80;

    return (
        <div className="space-y-1.5">
            <div className="flex items-baseline justify-between text-sm">
                <span className="text-muted-foreground">Posts consumed</span>
                <span
                    className={cn(
                        'font-medium tabular-nums',
                        isWarning && 'text-amber-600 dark:text-amber-500',
                    )}
                >
                    {consumed.toLocaleString()}
                    {cap !== null && ` / ${cap.toLocaleString()}`}
                </span>
            </div>
            <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
                <div
                    className={cn(
                        'h-full rounded-full transition-all',
                        isWarning ? 'bg-amber-500' : 'bg-foreground/60',
                    )}
                    style={{ width: `${percent}%` }}
                />
            </div>
        </div>
    );
}

function PercentUsedMeter({ percent }: { percent: number }) {
    const clamped = Math.min(100, Math.max(0, percent));
    const isWarning = percent > 80;

    return (
        <div className="flex items-center gap-2">
            <div className="h-1.5 w-16 overflow-hidden rounded-full bg-muted">
                <div
                    className={cn(
                        'h-full rounded-full',
                        isWarning ? 'bg-amber-500' : 'bg-foreground/50',
                    )}
                    style={{ width: `${clamped}%` }}
                />
            </div>
            <span
                className={cn(
                    'text-xs text-muted-foreground tabular-nums',
                    isWarning && 'text-amber-600 dark:text-amber-500',
                )}
            >
                {percent}%
            </span>
        </div>
    );
}

function UsageTable({
    title,
    description,
    empty,
    columns,
    children,
}: {
    title: string;
    description: string;
    empty: string;
    columns: string[];
    children: React.ReactNode;
}) {
    const hasRows = Array.isArray(children) ? children.length > 0 : !!children;

    return (
        <section className="space-y-4">
            <div className="space-y-1">
                <h2 className="text-sm font-medium">{title}</h2>
                <p className="text-sm text-muted-foreground">{description}</p>
            </div>

            <div className="min-w-0 rounded-md border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            {columns.map((column) => (
                                <TableHead key={column}>{column}</TableHead>
                            ))}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {hasRows ? (
                            children
                        ) : (
                            <TableRow>
                                <TableCell
                                    colSpan={columns.length}
                                    className="py-6 text-center text-sm text-muted-foreground"
                                >
                                    {empty}
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>
            </div>
        </section>
    );
}

function UsageStat({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-md border bg-muted/30 p-3">
            <div className="text-xs text-muted-foreground">{label}</div>
            <div className="mt-1 text-lg font-semibold">{value}</div>
        </div>
    );
}

function WorkspaceOwner({ owner }: { owner: DrilldownOwner | null }) {
    return (
        <div className="space-y-2">
            <h3 className="text-sm font-medium">Owner</h3>
            {owner ? (
                <div className="flex items-center gap-3 rounded-md border p-3">
                    <Avatar className="size-9">
                        <AvatarImage src={owner.avatar} alt={owner.name} />
                        <AvatarFallback>{owner.name.charAt(0)}</AvatarFallback>
                    </Avatar>
                    <div className="min-w-0">
                        <p className="truncate font-medium">{owner.name}</p>
                        <p className="truncate text-sm text-muted-foreground">
                            {owner.email}
                        </p>
                    </div>
                </div>
            ) : (
                <p className="rounded-md border p-3 text-sm text-muted-foreground">
                    This workspace has no owner assigned.
                </p>
            )}
        </div>
    );
}

function CostDeltaBadge({
    delta,
    currency,
}: {
    delta: number;
    currency: string;
}) {
    if (delta === 0) {
        return <Badge variant="outline">No change</Badge>;
    }

    const sign = delta > 0 ? '+' : '';

    return (
        <Badge variant={delta > 0 ? 'warning' : 'success'}>
            {sign}
            {formatMoney(delta, currency)}
        </Badge>
    );
}

export function formatMoney(value: number, currency: string) {
    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency,
        minimumFractionDigits: value === 0 ? 2 : 3,
        maximumFractionDigits: 3,
    }).format(value);
}

function formatLabel(value: string) {
    return value
        .split('_')
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
}

function formatPlatform(value: string | null) {
    if (!value || value === 'none') {
        return 'None';
    }

    if (value === 'x') {
        return 'X';
    }

    return formatLabel(value);
}

function formatDate(value: string) {
    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

InstanceUsage.layout = {
    breadcrumbs: [
        {
            title: 'Instance settings',
            href: InstanceSettingsController.edit().url,
        },
        {
            title: 'Usage',
            href: InstanceSettingsController.usage().url,
        },
    ],
};
