import { useRef, useState } from 'react';

import { parseSseChunk } from '@/lib/ai/sse';

type Handlers = {
    onDelta: (text: string) => void;
    onDone: () => void;
    onError: (message: string) => void;
};

type Status = 'idle' | 'streaming';

function csrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export function useAiStream() {
    const [status, setStatus] = useState<Status>('idle');
    const controllerRef = useRef<AbortController | null>(null);

    function cancel() {
        controllerRef.current?.abort();
        controllerRef.current = null;
        setStatus('idle');
    }

    async function run(
        url: string,
        body: Record<string, unknown>,
        handlers: Handlers,
    ) {
        cancel();
        const controller = new AbortController();
        controllerRef.current = controller;
        setStatus('streaming');

        try {
            const response = await fetch(url, {
                method: 'POST',
                signal: controller.signal,
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'text/event-stream',
                    'X-XSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(body),
            });

            if (!response.ok || !response.body) {
                handlers.onError(
                    response.status === 404
                        ? 'AI is not available.'
                        : `Request failed (${response.status}).`,
                );
                setStatus('idle');

                return;
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';

            for (;;) {
                const { done, value } = await reader.read();
                if (done) {
                    break;
                }
                buffer += decoder.decode(value, { stream: true });
                const { events, rest } = parseSseChunk(buffer);
                buffer = rest;
                for (const event of events) {
                    if (event.type === 'delta') {
                        handlers.onDelta(event.text);
                    } else if (event.type === 'error') {
                        handlers.onError(event.message);
                    } else {
                        handlers.onDone();
                    }
                }
            }
        } catch (error) {
            if (
                !(error instanceof DOMException && error.name === 'AbortError')
            ) {
                handlers.onError('The connection was interrupted.');
            }
        } finally {
            controllerRef.current = null;
            setStatus('idle');
        }
    }

    return { run, cancel, status };
}
