/**
 * Decorative, theme-tinted light that drifts behind the dashboard. Purely
 * cosmetic and `pointer-events-none`.
 *
 * It deliberately breaks out of the centered content column to span the full
 * width of the scroll area (the sidebar inset clips horizontal bleed, so there
 * is no page scrollbar). A radial mask feathers every edge, so the atmosphere
 * fades out instead of being sliced by the column's width — it reads as ambient
 * light, not a boxed panel.
 *
 * Layers: a slowly rotating conic sheen, three drifting/​scaling colour blobs,
 * and an SVG grain overlay for texture. All motion honors
 * `prefers-reduced-motion` (see app.css), leaving a static textured wash.
 */
export function DashboardAura() {
    return (
        <div
            aria-hidden="true"
            className="pointer-events-none absolute top-0 left-1/2 -z-10 h-[560px] w-screen -translate-x-1/2 overflow-hidden [mask-image:radial-gradient(125%_82%_at_50%_0%,black_42%,transparent)]"
        >
            {/* Rotating conic sheen. Centered with a negative margin (not
                translate) so the spin transform doesn't fight the positioning. */}
            <div className="absolute -top-[42%] left-1/2 -ml-[380px] size-[760px] animate-aura-spin rounded-full opacity-70 blur-2xl [background:conic-gradient(from_0deg,transparent,color-mix(in_oklch,var(--primary)_28%,transparent),transparent_45%,color-mix(in_oklch,var(--primary)_16%,transparent),transparent_75%)]" />

            {/* Drifting colour blobs, spread across the full-bleed canvas. */}
            <div className="absolute -top-32 left-[16%] size-[460px] animate-aura-a rounded-full bg-primary/30 blur-[80px]" />
            <div className="absolute -top-20 right-[16%] size-[400px] animate-aura-b rounded-full bg-emerald-400/25 blur-[80px]" />
            <div className="absolute top-6 left-1/2 -ml-[170px] size-[340px] animate-aura-a rounded-full bg-primary/20 blur-[90px] [animation-delay:-7s]" />

            {/* Grain texture. */}
            <div className="aura-grain absolute inset-0 opacity-15 mix-blend-soft-light" />
        </div>
    );
}
