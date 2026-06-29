import { useEffect, useState } from 'react';

import { isVideoEncodingSupported } from '@/lib/video-editor/support';

/**
 * Resolves the async WebCodecs encode-capability probe to a render-friendly
 * boolean — true when the browser can crop (re-encode) video. Defaults to
 * `false` until the probe resolves, so crop affordances only appear once
 * encoding is confirmed to actually work in this browser.
 */
export function useVideoEncodingSupported(): boolean {
    const [supported, setSupported] = useState(false);
    useEffect(() => {
        let active = true;
        void isVideoEncodingSupported().then((value) => {
            if (active) {
                setSupported(value);
            }
        });
        return () => {
            active = false;
        };
    }, []);
    return supported;
}
