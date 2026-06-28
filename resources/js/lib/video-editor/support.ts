export function isVideoEditingSupported(): boolean {
    return (
        typeof window !== 'undefined' &&
        'VideoEncoder' in window &&
        'VideoDecoder' in window
    );
}
