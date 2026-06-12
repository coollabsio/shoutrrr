import { Extension } from '@tiptap/core';
import type { Node as PMNode } from '@tiptap/pm/model';
import { Plugin, PluginKey } from '@tiptap/pm/state';
import { Decoration, DecorationSet } from '@tiptap/pm/view';

import type { PlatformName } from '../types';

/**
 * State the composer pushes into the plugin via `setSectionMarkerState`. The old
 * product read platform constants from a bundled `@/lib/platforms` module; this
 * project instead carries the active platform's `limit` (max chars) and
 * `threadMax` (non-null for single-post platforms like LinkedIn) — both already
 * available as Inertia page props (`PlatformLimits`).
 */
export interface MarkerState {
    platform: PlatformName;
    autoSplit: boolean;
    /** Max characters for one section/post on the active platform. */
    limit: number;
    /** Non-null for single-post platforms (no threading, e.g. LinkedIn). */
    threadMax: number | null;
}

declare module '@tiptap/core' {
    interface Commands<ReturnType> {
        sectionMarkers: {
            setSectionMarkerState: (state: MarkerState) => ReturnType;
        };
    }
}

const META_KEY = 'section_markers/config';

/** Exported so tests can inspect the plugin's DecorationSet directly. */
export const sectionMarkersKey = new PluginKey<{
    config: MarkerState;
    deco: DecorationSet;
}>('section_markers');
const pluginKey = sectionMarkersKey;

const DEFAULT_STATE: MarkerState = {
    platform: 'bluesky',
    autoSplit: true,
    limit: 300,
    threadMax: null,
};

/**
 * Client-side mirror of the active platform's length measure. X counts UTF-16
 * code units (JS string length); the others approximate with code points. The
 * server's grapheme/byte count remains authoritative.
 */
function measure(text: string, platform: PlatformName): number {
    // oxlint-disable-next-line no-misused-spread -- intentional code-point count
    return platform === 'x' ? text.length : [...text].length;
}

interface SectionMap {
    boundaryAfter: number[];
    sectionCount: number[];
    totalSections: number;
}

/**
 * Paragraph-aware greedy split. Each paragraph either joins the current section
 * (when `current + "\n\n" + para` fits in `limit`) or starts a fresh one. A
 * paragraph that alone exceeds `limit` becomes a single overflowing section — we
 * never split mid-paragraph, so markers always sit on paragraph boundaries.
 */
function deriveSectionMap(
    paragraphs: string[],
    platform: PlatformName,
    limit: number,
): SectionMap {
    const boundaryAfter: number[] = [];
    const sectionCount: number[] = [];

    let cur = '';
    let secIdx = 1;

    for (let i = 0; i < paragraphs.length; i++) {
        const para = paragraphs[i] ?? '';
        const joined = cur === '' ? para : `${cur}\n\n${para}`;

        if (cur === '' || measure(joined, platform) <= limit) {
            cur = joined;

            continue;
        }

        sectionCount.push(measure(cur, platform));
        boundaryAfter.push(i - 1);
        secIdx += 1;
        cur = para;
    }

    sectionCount.push(measure(cur, platform));

    return { boundaryAfter, sectionCount, totalSections: secIdx };
}

function makeMarkerDom(
    num: number,
    total: number,
    count: number,
    limit: number,
): HTMLElement {
    const wrap = document.createElement('div');
    wrap.className = 'section-marker';
    wrap.setAttribute('contenteditable', 'false');
    wrap.setAttribute('aria-hidden', 'true');
    const state = count > limit ? 'over' : count >= limit * 0.9 ? 'warn' : 'ok';
    wrap.dataset.state = state;
    wrap.innerHTML = `
    <span class="sm-rule"></span>
    <span class="sm-chip">
      <span class="sm-num">${String(num).padStart(2, '0')}/${String(total).padStart(2, '0')}</span>
      <span class="sm-dot"></span>
      <span class="sm-count">${count}/${limit}</span>
    </span>
    <span class="sm-rule"></span>
  `;

    return wrap;
}

function computeDecorations(doc: PMNode, config: MarkerState): DecorationSet {
    if (!config.autoSplit) {
        return DecorationSet.empty;
    }
    // Single-post platform (e.g. LinkedIn) — never threads, so no markers.
    if (config.threadMax !== null) {
        return DecorationSet.empty;
    }
    const limit = config.limit > 0 ? config.limit : Infinity;

    // Collect paragraph blocks and their end positions.
    const paraTexts: string[] = [];
    const paraEnds: number[] = [];
    doc.descendants((node, pos) => {
        if (node.type.name === 'paragraph') {
            paraTexts.push(node.textContent);
            paraEnds.push(pos + node.nodeSize);

            return false; // don't descend further
        }

        return undefined;
    });

    if (paraTexts.length < 2) {
        return DecorationSet.empty;
    }

    const map = deriveSectionMap(paraTexts, config.platform, limit);
    if (map.totalSections < 2) {
        return DecorationSet.empty;
    }

    const decorations: Decoration[] = [];
    map.boundaryAfter.forEach((paraIdx, i) => {
        const pos = paraEnds[paraIdx];
        if (pos === undefined) {
            return;
        }
        // The section that just CLOSED at paraIdx is #(i+1); the NEXT section is
        // #(i+2). Display the next-section number on the marker.
        const nextNum = i + 2;
        const count = map.sectionCount[i + 1] ?? 0;
        decorations.push(
            Decoration.widget(
                pos,
                () => makeMarkerDom(nextNum, map.totalSections, count, limit),
                {
                    side: 1,
                    // Embed every rendered value in the key. ProseMirror treats
                    // two widgets with the same key as equal (WidgetType.eq) and
                    // reuses the DOM without re-invoking render — so a bare
                    // `section-${i}` key would freeze the count after first
                    // render. Include count / nextNum / total / limit so the key
                    // changes exactly when the visible label needs to change.
                    key: `section-${i}:${nextNum}/${map.totalSections}:${count}/${limit}`,
                },
            ),
        );
    });

    return DecorationSet.create(doc, decorations);
}

export const SectionMarkers = Extension.create<MarkerState>({
    name: 'section_markers',

    addOptions() {
        return { ...DEFAULT_STATE };
    },

    addCommands() {
        return {
            setSectionMarkerState:
                (state: MarkerState) =>
                ({ tr, dispatch }) => {
                    if (dispatch) {
                        tr.setMeta(META_KEY, state);
                        dispatch(tr);
                    }

                    return true;
                },
        };
    },

    addProseMirrorPlugins() {
        const options = this.options;

        return [
            new Plugin({
                key: pluginKey,
                state: {
                    init: (_, editorState) => {
                        const config = { ...DEFAULT_STATE, ...options };

                        return {
                            config,
                            deco: computeDecorations(editorState.doc, config),
                        };
                    },
                    apply: (tr, prev) => {
                        const metaUpdate = tr.getMeta(META_KEY) as
                            | MarkerState
                            | undefined;
                        const config = metaUpdate ?? prev.config;
                        if (!tr.docChanged && !metaUpdate) {
                            return prev;
                        }

                        return {
                            config,
                            deco: computeDecorations(tr.doc, config),
                        };
                    },
                },
                props: {
                    decorations: (editorState) =>
                        pluginKey.getState(editorState)?.deco ?? null,
                },
            }),
        ];
    },
});
