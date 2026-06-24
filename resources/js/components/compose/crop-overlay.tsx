import { useRef, useState } from 'react';

import type { Corner } from '@/lib/image-editor/layout';
import { moveCropRect, resizeCorner } from '@/lib/image-editor/layout';
import type { CropRect } from '@/lib/image-editor/settings';
import { cn } from '@/lib/utils';

type Props = {
    imageSrc: string;
    sourceSize: { width: number; height: number };
    rect: CropRect;
    ratio: number | null;
    onChange: (rect: CropRect) => void;
    /** Available display area (the editor's measured canvas); the crop UI fits within it. */
    maxW?: number;
    maxH?: number;
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

const DISPLAY_FALLBACK = 420;

export function CropOverlay({
    imageSrc,
    sourceSize,
    rect,
    ratio,
    onChange,
    maxW = DISPLAY_FALLBACK,
    maxH = DISPLAY_FALLBACK,
}: Props) {
    const boxRef = useRef<HTMLDivElement | null>(null);
    const [drag, setDrag] = useState<{
        kind: 'move' | Corner;
        startX: number;
        startY: number;
        startRect: CropRect;
    } | null>(null);

    // Scale source pixels → on-screen display pixels, fitting the available area.
    const scale = Math.min(
        (maxW || DISPLAY_FALLBACK) / sourceSize.width,
        (maxH || DISPLAY_FALLBACK) / sourceSize.height,
    );
    const dispW = sourceSize.width * scale;
    const dispH = sourceSize.height * scale;

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
            ref={boxRef}
            className="relative mx-auto touch-none select-none"
            style={{ width: dispW, height: dispH }}
            onPointerMove={onPointerMove}
            onPointerUp={endDrag}
            onPointerLeave={endDrag}
        >
            <img
                src={imageSrc}
                alt=""
                draggable={false}
                className="size-full"
            />
            <div className="pointer-events-none absolute inset-0 bg-black/40" />
            <div
                className="absolute cursor-move border-2 border-white shadow-[0_0_0_9999px_rgba(0,0,0,0.4)]"
                style={{
                    left: rect.x * scale,
                    top: rect.y * scale,
                    width: rect.width * scale,
                    height: rect.height * scale,
                }}
                onPointerDown={(e) => onPointerDown(e, 'move')}
            >
                <img
                    src={imageSrc}
                    alt=""
                    draggable={false}
                    className="pointer-events-none absolute max-w-none"
                    style={{
                        width: dispW,
                        height: dispH,
                        left: -rect.x * scale,
                        top: -rect.y * scale,
                    }}
                />
                {CORNERS.map(({ corner, className }) => (
                    <button
                        key={corner}
                        type="button"
                        aria-label={`Resize ${corner}`}
                        onPointerDown={(e) => onPointerDown(e, corner)}
                        className={cn(
                            // Larger hit area on touch; compact dot on pointer devices.
                            'absolute size-5 rounded-full border border-border bg-white shadow-sm md:size-3',
                            className,
                        )}
                    />
                ))}
            </div>
        </div>
    );
}
