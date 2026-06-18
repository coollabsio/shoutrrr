import { Line, LineChart } from 'recharts';

/**
 * A tiny axis-less trend line for a single metric.
 * Renders nothing when fewer than 2 data points are present
 * (a sparkline needs at least one segment to be meaningful).
 */
export function MetricSparkline({
    values,
    color = 'var(--muted-foreground)',
}: {
    values: number[];
    color?: string;
}) {
    if (values.length < 2) {
        return null;
    }

    const data = values.map((v, i) => ({ i, v }));

    return (
        <LineChart width={52} height={16} data={data}>
            <Line
                type="monotone"
                dataKey="v"
                stroke={color}
                strokeWidth={1.5}
                dot={false}
                isAnimationActive={false}
            />
        </LineChart>
    );
}
