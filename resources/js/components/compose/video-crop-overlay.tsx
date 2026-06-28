import { useState } from 'react';

import type { Corner } from '@/lib/image-editor/layout';
import { moveCropRect, resizeCorner } from '@/lib/image-editor/layout';
import type { CropRect } from '@/lib/image-editor/settings';
import { cn } from '@/lib/utils';

type Props = {
    sourceSize: { width: number; height: number };
    rect: CropRect;
    ratio: number | null;
    onChange: (rect: CropRect) => void;
    /** On-screen display width of the video element the overlay sits above. */
    dispW: number;
};

const CORNERS: { corner: Corner; className: string }[] = [
    {
        corner: 'nw',
        className:
            'left-0 top-0 -translate-x-1/2 -translate-y-1/2 cursor-nwse-resize',
    },
    {
        corner: 'ne',
        className:
            'right-0 top-0 translate-x-1/2 -translate-y-1/2 cursor-nesw-resize',
    },
    {
        corner: 'se',
        className:
            'right-0 bottom-0 translate-x-1/2 translate-y-1/2 cursor-nwse-resize',
    },
    {
        corner: 'sw',
        className:
            'left-0 bottom-0 -translate-x-1/2 translate-y-1/2 cursor-nesw-resize',
    },
];

export function VideoCropOverlay({
    sourceSize,
    rect,
    ratio,
    onChange,
    dispW,
}: Props) {
    const [drag, setDrag] = useState<{
        kind: 'move' | Corner;
        startX: number;
        startY: number;
        startRect: CropRect;
    } | null>(null);

    // Scale source pixels → on-screen display pixels.
    const scale = dispW / sourceSize.width;

    function onPointerDown(e: React.PointerEvent, kind: 'move' | Corner) {
        e.preventDefault();
        e.stopPropagation();
        (e.target as Element).setPointerCapture?.(e.pointerId);
        setDrag({
            kind,
            startX: e.clientX,
            startY: e.clientY,
            startRect: rect,
        });
    }

    function onPointerMove(e: React.PointerEvent) {
        if (!drag) {
            return;
        }
        // Convert display-space delta back to source pixels.
        const dx = (e.clientX - drag.startX) / scale;
        const dy = (e.clientY - drag.startY) / scale;
        const next =
            drag.kind === 'move'
                ? moveCropRect(
                      drag.startRect,
                      dx,
                      dy,
                      sourceSize.width,
                      sourceSize.height,
                  )
                : resizeCorner(
                      drag.startRect,
                      drag.kind,
                      dx,
                      dy,
                      ratio,
                      sourceSize.width,
                      sourceSize.height,
                  );
        onChange(next);
    }

    function endDrag() {
        setDrag(null);
    }

    return (
        <div
            className="absolute inset-0 touch-none select-none"
            onPointerMove={onPointerMove}
            onPointerUp={endDrag}
            onPointerLeave={endDrag}
        >
            {/* Crop box — transparent interior so the live video beneath shows through.
                The large box-shadow spread dims everything outside the crop region. */}
            <div
                className="absolute cursor-move shadow-[0_0_0_9999px_rgba(0,0,0,0.5)]"
                style={{
                    left: rect.x * scale,
                    top: rect.y * scale,
                    width: rect.width * scale,
                    height: rect.height * scale,
                }}
                onPointerDown={(e) => onPointerDown(e, 'move')}
            >
                {/* White border outline */}
                <div className="pointer-events-none absolute inset-0 z-10 border-2 border-white" />
                {CORNERS.map(({ corner, className }) => (
                    <button
                        key={corner}
                        type="button"
                        aria-label={`Resize ${corner}`}
                        onPointerDown={(e) => onPointerDown(e, corner)}
                        className={cn(
                            // Larger hit area on touch; compact dot on pointer devices.
                            'absolute z-10 size-5 rounded-full border border-border bg-white shadow-sm md:size-3',
                            className,
                        )}
                    />
                ))}
            </div>
        </div>
    );
}
