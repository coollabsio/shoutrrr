import { Head, router } from '@inertiajs/react';

import InstanceSettingsController from '@/actions/App/Http/Controllers/Settings/InstanceSettingsController';
import Heading from '@/components/common/heading';
import { Badge } from '@/components/ui/badge';
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

export default function InstanceUsage({
    workspace_options,
    platforms,
    filters,
    comparison_periods,
    pricing_source,
    summaries,
    counters,
    error_events,
}: Props) {
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
                                )}
                            </TableCell>
                            <TableCell>
                                <CostDeltaBadge
                                    delta={summary.estimated_cost_delta_usd}
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
                                      )
                                    : '—'}
                            </TableCell>
                            <TableCell>
                                {counter.pricing
                                    ? `${counter.pricing.label} @ ${formatMoney(
                                          counter.pricing.unit_cost_usd,
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

function CostDeltaBadge({ delta }: { delta: number }) {
    if (delta === 0) {
        return <Badge variant="outline">No change</Badge>;
    }

    const sign = delta > 0 ? '+' : '';

    return (
        <Badge variant={delta > 0 ? 'warning' : 'success'}>
            {sign}
            {formatMoney(delta)}
        </Badge>
    );
}

function formatMoney(value: number) {
    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: 'USD',
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
