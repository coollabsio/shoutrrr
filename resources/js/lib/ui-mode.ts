export type UiMode = 'agentic' | 'classic';

/**
 * Agentic is the default. Classic applies only when the user explicitly chose it.
 */
export function resolveUiMode(value: unknown): UiMode {
    return value === 'classic' ? 'classic' : 'agentic';
}
