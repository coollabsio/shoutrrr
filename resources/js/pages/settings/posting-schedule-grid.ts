export const WEEKDAYS = [
    'Sun',
    'Mon',
    'Tue',
    'Wed',
    'Thu',
    'Fri',
    'Sat',
] as const;

export type Slot = { weekday: number; hour: number; position?: number };

export function cellId(weekday: number, hour: number): number {
    return weekday * 24 + hour;
}

export function hourLabel(hour: number): string {
    if (hour === 0) return '12a';
    if (hour === 12) return '12p';
    return hour < 12 ? `${hour}a` : `${hour - 12}p`;
}

export function slotsToSet(slots: Slot[]): Set<number> {
    const s = new Set<number>();
    for (const slot of slots) s.add(cellId(slot.weekday, slot.hour));
    return s;
}

export function setToSlots(set: Set<number>): Slot[] {
    return [...set]
        .toSorted((a, b) => a - b)
        .map((n, index) => ({
            weekday: Math.floor(n / 24),
            hour: n % 24,
            position: index,
        }));
}

export function setsEqual(a: Set<number>, b: Set<number>): boolean {
    if (a.size !== b.size) return false;
    for (const v of a) if (!b.has(v)) return false;
    return true;
}

export function countDiff(a: Set<number>, b: Set<number>): number {
    let n = 0;
    for (const v of a) if (!b.has(v)) n += 1;
    return n;
}
