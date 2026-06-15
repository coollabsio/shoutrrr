export type Slot = { weekday: number; hour: number; minute: number };

/**
 * Display order: Monday-first, Sunday last. `weekday` maps to the backend's
 * 0=Sunday … 6=Saturday convention.
 */
export const DISPLAY_DAYS: { weekday: number; label: string }[] = [
    { weekday: 1, label: 'Mon' },
    { weekday: 2, label: 'Tue' },
    { weekday: 3, label: 'Wed' },
    { weekday: 4, label: 'Thu' },
    { weekday: 5, label: 'Fri' },
    { weekday: 6, label: 'Sat' },
    { weekday: 0, label: 'Sun' },
];

function slotKey(weekday: number, hour: number, minute: number): number {
    return (weekday * 24 + hour) * 60 + minute;
}

/** Dedupe and sort a slot list by (weekday, hour, minute). */
export function normalizeSlots(slots: Slot[]): Slot[] {
    const seen = new Set<number>();
    const out: Slot[] = [];
    for (const s of slots) {
        const key = slotKey(s.weekday, s.hour, s.minute);
        if (!seen.has(key)) {
            seen.add(key);
            out.push({ weekday: s.weekday, hour: s.hour, minute: s.minute });
        }
    }

    return out.toSorted(
        (a, b) =>
            slotKey(a.weekday, a.hour, a.minute) -
            slotKey(b.weekday, b.hour, b.minute),
    );
}

export function hasSlot(
    slots: Slot[],
    weekday: number,
    hour: number,
    minute: number,
): boolean {
    return slots.some(
        (s) => s.weekday === weekday && s.hour === hour && s.minute === minute,
    );
}

export function addSlot(
    slots: Slot[],
    weekday: number,
    hour: number,
    minute: number,
): Slot[] {
    return hasSlot(slots, weekday, hour, minute)
        ? slots
        : normalizeSlots([...slots, { weekday, hour, minute }]);
}

export function removeSlot(
    slots: Slot[],
    weekday: number,
    hour: number,
    minute: number,
): Slot[] {
    return slots.filter(
        (s) =>
            !(s.weekday === weekday && s.hour === hour && s.minute === minute),
    );
}

/** Ascending {hour, minute} times for a given weekday. */
export function timesForDay(
    slots: Slot[],
    weekday: number,
): { hour: number; minute: number }[] {
    return slots
        .filter((s) => s.weekday === weekday)
        .map((s) => ({ hour: s.hour, minute: s.minute }))
        .toSorted((a, b) => a.hour * 60 + a.minute - (b.hour * 60 + b.minute));
}

/** Merge `additions` into `current`, deduped and sorted. */
export function mergeSlots(current: Slot[], additions: Slot[]): Slot[] {
    return normalizeSlots([...current, ...additions]);
}

export type Preset = { label: string; slots: Slot[] };

function daysAt(weekdays: number[], hours: number[]): Slot[] {
    const out: Slot[] = [];
    for (const weekday of weekdays) {
        for (const hour of hours) {
            out.push({ weekday, hour, minute: 0 });
        }
    }

    return out;
}

export const PRESETS: Preset[] = [
    { label: 'Weekdays 9am', slots: daysAt([1, 2, 3, 4, 5], [9]) },
    { label: 'Weekdays 9am & 5pm', slots: daysAt([1, 2, 3, 4, 5], [9, 17]) },
    { label: 'Mon/Wed/Fri 10am', slots: daysAt([1, 3, 5], [10]) },
    { label: 'Every day 12pm', slots: daysAt([0, 1, 2, 3, 4, 5, 6], [12]) },
];

/** Copy Monday's times onto Tue–Fri, merged with the current set. */
export function copyMondayToWeekdays(current: Slot[]): Slot[] {
    const mondayTimes = timesForDay(current, 1);
    const additions: Slot[] = [];
    for (const weekday of [2, 3, 4, 5]) {
        for (const t of mondayTimes) {
            additions.push({ weekday, hour: t.hour, minute: t.minute });
        }
    }

    return mergeSlots(current, additions);
}

/** "9:37 AM" label for a slot time. */
export function formatTime(hour: number, minute: number): string {
    const meridiem = hour < 12 ? 'AM' : 'PM';
    const hour12 = hour % 12 === 0 ? 12 : hour % 12;

    return `${hour12}:${String(minute).padStart(2, '0')} ${meridiem}`;
}

/** True when two slot lists describe the same set of (weekday, hour, minute). */
export function slotsEqual(a: Slot[], b: Slot[]): boolean {
    const na = normalizeSlots(a);
    const nb = normalizeSlots(b);
    if (na.length !== nb.length) {
        return false;
    }

    return na.every(
        (s, i) =>
            s.weekday === nb[i].weekday &&
            s.hour === nb[i].hour &&
            s.minute === nb[i].minute,
    );
}
