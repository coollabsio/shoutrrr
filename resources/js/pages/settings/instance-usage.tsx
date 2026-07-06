import { Head, router, useHttp } from '@inertiajs/react';
import { useState } from 'react';

import InstanceSettingsController from '@/actions/App/Http/Controllers/Settings/InstanceSettingsController';
import Heading from '@/components/common/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

type WorkspaceOption = {
    id: string;
    name: string;
};

type PlatformOption = {
    value: string;
    label: string;
};

type UsageSummary = {
    workspace: WorkspaceOption;
    current_event_count: number;
    current_total_quota: number;
    previous_total_quota: number;
    quota_delta: number;
    quota_delta_percent: number | null;
    current_estimated_cost_usd: number;
    previous_estimated_cost_usd: number;
    estimated_cost_delta_usd: number;
    publish_quota: number;
    external_api_quota: number;
    api_request_quota: number;
    posts_quota: number;
};

type PricingEstimate = {
    resource: string;
    label: string;
    unit_cost_usd: number;
    estimated_cost_usd: number;
};

type UsageCounter = {
    id: string;
    workspace: WorkspaceOption;
    period_start: string;
    period_end: string;
    category: string;
    platform: string;
    operation: string;
    event_count: number;
    total_quota: number;
    pricing: PricingEstimate | null;
};

type UsageErrorEvent = {
    id: string;
    workspace: WorkspaceOption;
    category: string;
    platform: string;
    operation: string;
    quota_weight: number;
    succeeded: boolean;
    meta: Record<string, unknown> | null;
    occurred_at: string;
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
    workspace_options: WorkspaceOption[];
    platforms: PlatformOption[];
    filters: {
        workspace: string | null;
        platform: string | null;
    };
    comparison_periods: {
        current: string;
        previous: string;
    };
    pricing_source: string;
    pricing_currency: string;
    x_usage_available: boolean;
    summaries: UsageSummary[];
    counters: UsageCounter[];
    error_events: UsageErrorEvent[];
};

const allWorkspacesValue = 'all';
const allPlatformsValue = 'all';

export function usageQuery(workspace: string | null, platform: string | null) {
    return {
        ...(workspace ? { workspace } : {}),
        ...(platform ? { platform } : {}),
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
    workspace_options,
    platforms,
    filters,
    comparison_periods,
    pricing_source,
    pricing_currency,
    x_usage_available,
    summaries,
    counters,
    error_events,
}: Props) {
    const xUsageHttp = useHttp<Record<string, never>, XUsageResponse>({});
    const [xUsage, setXUsage] = useState<XUsageResponse | null>(null);
    const [xUsageError, setXUsageError] = useState<string | null>(null);

    function updateFilters(next: Partial<Props['filters']>) {
        const workspace = Object.hasOwn(next, 'workspace')
            ? next.workspace
            : filters.workspace;
        const platform = Object.hasOwn(next, 'platform')
            ? next.platform
            : filters.platform;

        router.get(
            InstanceSettingsController.usage({
                query: usageQuery(workspace ?? null, platform ?? null),
            }).url,
            {},
            {
                preserveScroll: true,
                preserveState: true,
            },
        );
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
                        description="Review tracked platform API usage by workspace. Counters show successful usage; estimates use mapped X API pricing where available."
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
                        <Select
                            value={filters.workspace ?? allWorkspacesValue}
                            onValueChange={(workspace) =>
                                updateFilters({
                                    workspace:
                                        workspace === allWorkspacesValue
                                            ? null
                                            : workspace,
                                })
                            }
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="Filter workspace" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={allWorkspacesValue}>
                                    All workspaces
                                </SelectItem>
                                {workspace_options.map((workspace) => (
                                    <SelectItem
                                        key={workspace.id}
                                        value={workspace.id}
                                    >
                                        {workspace.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select
                            value={filters.platform ?? allPlatformsValue}
                            onValueChange={(platform) =>
                                updateFilters({
                                    platform:
                                        platform === allPlatformsValue
                                            ? null
                                            : platform,
                                })
                            }
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="Filter platform" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={allPlatformsValue}>
                                    All platforms
                                </SelectItem>
                                {platforms.map((platform) => (
                                    <SelectItem
                                        key={platform.value}
                                        value={platform.value}
                                    >
                                        {platform.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
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
                        <div className="mt-4 space-y-4">
                            <div className="grid gap-3 sm:grid-cols-3">
                                <UsageStat
                                    label="Posts consumed"
                                    value={xUsageTotal(
                                        xUsage.data,
                                    ).toLocaleString()}
                                />
                                <UsageStat
                                    label="Monthly cap"
                                    value={
                                        xUsage.data?.project_cap?.toLocaleString() ??
                                        '—'
                                    }
                                />
                                <UsageStat
                                    label="Cap reset"
                                    value={
                                        typeof xUsage.data?.cap_reset_day ===
                                        'number'
                                            ? `${xUsage.data.cap_reset_day} days`
                                            : '—'
                                    }
                                />
                            </div>
                            <p className="text-xs text-muted-foreground">
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
                            <pre className="max-h-72 overflow-auto rounded-md bg-muted p-3 text-xs">
                                {JSON.stringify(xUsage.data, null, 2)}
                            </pre>
                        </div>
                    )}
                </section>

                <UsageTable
                    title="Workspace comparison"
                    description={`Current period ${comparison_periods.current} compared with ${comparison_periods.previous}. Totals use quota weight so this is ready for future limits.`}
                    empty="No usage counters recorded for the comparison periods."
                    columns={[
                        'Workspace',
                        'Current quota',
                        'Previous quota',
                        'Quota change',
                        'Est. cost',
                        'Cost change',
                        'Events',
                        'Posts',
                        'Publish',
                        'External API',
                        'API requests',
                    ]}
                >
                    {summaries.map((summary) => (
                        <TableRow key={summary.workspace.id}>
                            <TableCell>{summary.workspace.name}</TableCell>
                            <TableCell>{summary.current_total_quota}</TableCell>
                            <TableCell>
                                {summary.previous_total_quota}
                            </TableCell>
                            <TableCell>
                                <DeltaBadge
                                    delta={summary.quota_delta}
                                    percent={summary.quota_delta_percent}
                                />
                            </TableCell>
                            <TableCell>
                                {formatMoney(
                                    summary.current_estimated_cost_usd,
                                    pricing_currency,
                                )}
                            </TableCell>
                            <TableCell>
                                <CostDeltaBadge
                                    delta={summary.estimated_cost_delta_usd}
                                    currency={pricing_currency}
                                />
                            </TableCell>
                            <TableCell>{summary.current_event_count}</TableCell>
                            <TableCell>{summary.posts_quota}</TableCell>
                            <TableCell>{summary.publish_quota}</TableCell>
                            <TableCell>{summary.external_api_quota}</TableCell>
                            <TableCell>{summary.api_request_quota}</TableCell>
                        </TableRow>
                    ))}
                </UsageTable>

                <UsageTable
                    title="Monthly counters"
                    description="Aggregated successful usage for each recorded period."
                    empty="No usage counters recorded yet."
                    columns={[
                        'Workspace',
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
                    {counters.map((counter) => (
                        <TableRow key={counter.id}>
                            <TableCell>{counter.workspace.name}</TableCell>
                            <TableCell>
                                {counter.period_start} → {counter.period_end}
                            </TableCell>
                            <TableCell>
                                {formatLabel(counter.category)}
                            </TableCell>
                            <TableCell>
                                {formatPlatform(counter.platform)}
                            </TableCell>
                            <TableCell>
                                {formatLabel(counter.operation)}
                            </TableCell>
                            <TableCell>{counter.event_count}</TableCell>
                            <TableCell>{counter.total_quota}</TableCell>
                            <TableCell>
                                {counter.pricing
                                    ? formatMoney(
                                          counter.pricing.estimated_cost_usd,
                                          pricing_currency,
                                      )
                                    : '—'}
                            </TableCell>
                            <TableCell>
                                {counter.pricing
                                    ? `${counter.pricing.label} @ ${formatMoney(
                                          counter.pricing.unit_cost_usd,
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
                        'Workspace',
                        'Category',
                        'Platform',
                        'Operation',
                        'Quota',
                        'Error meta',
                    ]}
                >
                    {error_events.map((event) => (
                        <TableRow key={event.id}>
                            <TableCell>
                                {formatDate(event.occurred_at)}
                            </TableCell>
                            <TableCell>{event.workspace.name}</TableCell>
                            <TableCell>{formatLabel(event.category)}</TableCell>
                            <TableCell>
                                {formatPlatform(event.platform)}
                            </TableCell>
                            <TableCell>
                                {formatLabel(event.operation)}
                            </TableCell>
                            <TableCell>{event.quota_weight}</TableCell>
                            <TableCell className="max-w-64 truncate font-mono text-xs text-muted-foreground">
                                {event.meta ? JSON.stringify(event.meta) : '—'}
                            </TableCell>
                        </TableRow>
                    ))}
                </UsageTable>
            </div>
        </>
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

function DeltaBadge({
    delta,
    percent,
}: {
    delta: number;
    percent: number | null;
}) {
    if (delta === 0) {
        return <Badge variant="outline">No change</Badge>;
    }

    const sign = delta > 0 ? '+' : '';
    const percentLabel = percent === null ? 'new' : `${sign}${percent}%`;

    return (
        <Badge variant={delta > 0 ? 'warning' : 'success'}>
            {sign}
            {delta} ({percentLabel})
        </Badge>
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
