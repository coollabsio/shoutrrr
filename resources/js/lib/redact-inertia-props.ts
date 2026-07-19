/**
 * Redact and bound Inertia page props before attaching them to a feedback
 * diagnostics file. Values under keys that look like credentials/secrets are
 * replaced with a marker; strings, arrays, and nesting depth are capped so a
 * data-heavy page (e.g. a long list) can't bloat the report. The shape is
 * preserved so the team can see WHAT the page held, just not the secrets.
 *
 * This is best-effort scrubbing by key name, not a security boundary — the
 * feedback endpoint is authenticated and reports go to the team's own Discord.
 * It exists so tokens/keys aren't casually shipped into that channel (this app
 * flashes a `plainTextApiKey` prop, for example).
 */

const SENSITIVE_KEY =
    /pass(word|wd)?|secret|token|api[-_]?key|access[-_]?key|private[-_]?key|credential|cookie|session|csrf|xsrf|bearer|signature|authorization|webhook|\botp\b|\bpin\b|\bssn\b/i;

const REDACTED = '[redacted]';
const MAX_DEPTH = 6;
const MAX_STRING = 500;
const MAX_ARRAY = 50;

export function redactInertiaProps(input: unknown): unknown {
    return walk(input, 0);
}

function walk(value: unknown, depth: number): unknown {
    if (value === null || typeof value !== 'object') {
        if (typeof value === 'string' && value.length > MAX_STRING) {
            return `${value.slice(0, MAX_STRING)}… (${value.length} chars)`;
        }

        return value;
    }

    if (depth >= MAX_DEPTH) {
        return Array.isArray(value) ? '[array]' : '[object]';
    }

    if (Array.isArray(value)) {
        const items = value
            .slice(0, MAX_ARRAY)
            .map((item) => walk(item, depth + 1));
        if (value.length > MAX_ARRAY) {
            items.push(`… (${value.length - MAX_ARRAY} more)`);
        }

        return items;
    }

    const out: Record<string, unknown> = {};
    for (const [key, val] of Object.entries(value)) {
        out[key] = SENSITIVE_KEY.test(key) ? REDACTED : walk(val, depth + 1);
    }

    return out;
}
