export type AiEvent =
    | { type: 'delta'; text: string }
    | { type: 'done' }
    | { type: 'error'; message: string };

/**
 * Split a raw SSE buffer into complete events plus the unparsed remainder.
 * Frames are separated by a blank line; each carries a single `data:` JSON line.
 */
export function parseSseChunk(buffer: string): {
    events: AiEvent[];
    rest: string;
} {
    const events: AiEvent[] = [];
    const parts = buffer.split('\n\n');
    const rest = parts.pop() ?? '';

    for (const part of parts) {
        const line = part.split('\n').find((l) => l.startsWith('data:'));
        if (!line) {
            continue;
        }
        try {
            events.push(
                JSON.parse(line.slice('data:'.length).trim()) as AiEvent,
            );
        } catch {
            // Ignore malformed frames.
        }
    }

    return { events, rest };
}
