import { Extension } from '@tiptap/core';
import type { Node as PMNode } from '@tiptap/pm/model';
import { Plugin, PluginKey } from '@tiptap/pm/state';
import { Decoration, DecorationSet } from '@tiptap/pm/view';

/**
 * Places one block-widget decoration at the end of each authored segment — just
 * before the `sectionBreak` that closes it, or at the doc end for the last
 * segment — so a per-segment media row can be portaled INTO the editor flow,
 * sitting between the segment's last line and its division line rather than in a
 * separate strip below the editor.
 *
 * Each anchor is keyed by the segment's ref (the opening break's `breakId`, or
 * `__head__` for the first), and its DOM node is cached per ref so React portals
 * stay attached across the constant decoration churn of typing.
 */
export const segmentMediaAnchorsKey = new PluginKey('segmentMediaAnchors');

export type SegmentAnchor = { ref: string; el: HTMLElement };

/**
 * The ordered segment refs for a doc's section breaks, mirroring
 * `docToSegmentsWithBreaks`/`segmentRefs`: `__head__` first, then each break's
 * `breakId` (or the positional `'b' + index` fallback for an id-less break).
 */
export function anchorRefsFromBreaks(breaks: Array<string | null>): string[] {
    const refs = ['__head__'];
    breaks.forEach((id, index) => refs.push(id ?? 'b' + index));

    return refs;
}

/**
 * Walk the top-level nodes and pair each segment's ref with the doc position
 * where its row should sit (before the closing break, or `doc.content.size` for
 * the last segment).
 */
function anchorPlan(doc: PMNode): { ref: string; pos: number }[] {
    const plan: { ref: string; pos: number }[] = [];
    let currentRef = '__head__';
    let breakIndex = 0;
    doc.forEach((node, offset) => {
        if (node.type.name === 'sectionBreak') {
            plan.push({ ref: currentRef, pos: offset });
            currentRef =
                (node.attrs.breakId as string | null) ?? 'b' + breakIndex;
            breakIndex += 1;
        }
    });
    plan.push({ ref: currentRef, pos: doc.content.size });

    return plan;
}

export const SegmentMediaAnchors = Extension.create({
    name: 'segmentMediaAnchors',

    addProseMirrorPlugins() {
        // Cache anchor DOM nodes by ref so the same element is reused across
        // transactions — the portal target must stay stable or React tears the
        // media row down and rebuilds it on every keystroke. EditorBody reads
        // these nodes off the DOM (by `data-segment-ref`) to portal each
        // segment's media row into them.
        const cache = new Map<string, HTMLElement>();

        function anchorEl(ref: string): HTMLElement {
            let el = cache.get(ref);
            if (!el) {
                el = document.createElement('div');
                el.className = 'segment-media-anchor';
                el.setAttribute('contenteditable', 'false');
                el.setAttribute('data-segment-ref', ref);
                cache.set(ref, el);
            }

            return el;
        }

        return [
            new Plugin({
                key: segmentMediaAnchorsKey,
                props: {
                    decorations: (state) => {
                        const plan = anchorPlan(state.doc);
                        const live = new Set(plan.map((a) => a.ref));
                        for (const ref of [...cache.keys()]) {
                            if (!live.has(ref)) {
                                cache.delete(ref);
                            }
                        }

                        return DecorationSet.create(
                            state.doc,
                            plan.map((a) =>
                                Decoration.widget(
                                    a.pos,
                                    () => anchorEl(a.ref),
                                    {
                                        side: -1,
                                        key: 'sma-' + a.ref,
                                        ignoreSelection: true,
                                    },
                                ),
                            ),
                        );
                    },
                },
            }),
        ];
    },
});
