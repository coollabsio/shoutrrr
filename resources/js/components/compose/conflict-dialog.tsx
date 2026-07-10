import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { PostView } from '@/types/compose';

type Props = {
    open: boolean;
    /** The user's local base text (their version). */
    myBaseText: string;
    /** The server's latest post (their version). */
    serverPost: PostView;
    onKeepMine: () => void;
    onUseServer: () => void;
};

type DiffPart = { kind: 'equal' | 'added' | 'removed'; text: string };

/**
 * A simple word-level diff via the longest-common-subsequence of whitespace
 * tokens. `removed` words exist only in `a` (the user's version); `added` words
 * exist only in `b` (the server's version).
 */
function wordDiff(a: string, b: string): DiffPart[] {
    const aw = a.split(/(\s+)/);
    const bw = b.split(/(\s+)/);
    const n = aw.length;
    const m = bw.length;
    const lcs: number[][] = Array.from({ length: n + 1 }, () =>
        new Array<number>(m + 1).fill(0),
    );
    for (let i = n - 1; i >= 0; i--) {
        for (let j = m - 1; j >= 0; j--) {
            lcs[i]![j] =
                aw[i] === bw[j]
                    ? lcs[i + 1]![j + 1]! + 1
                    : Math.max(lcs[i + 1]![j]!, lcs[i]![j + 1]!);
        }
    }

    const parts: DiffPart[] = [];
    const push = (kind: DiffPart['kind'], text: string) => {
        const last = parts[parts.length - 1];
        if (last && last.kind === kind) {
            last.text += text;
        } else {
            parts.push({ kind, text });
        }
    };

    let i = 0;
    let j = 0;
    while (i < n && j < m) {
        if (aw[i] === bw[j]) {
            push('equal', aw[i]!);
            i++;
            j++;
        } else if (lcs[i + 1]![j]! >= lcs[i]![j + 1]!) {
            push('removed', aw[i]!);
            i++;
        } else {
            push('added', bw[j]!);
            j++;
        }
    }
    while (i < n) {
        push('removed', aw[i]!);
        i++;
    }
    while (j < m) {
        push('added', bw[j]!);
        j++;
    }

    return parts;
}

export function ConflictDialog({
    open,
    myBaseText,
    serverPost,
    onKeepMine,
    onUseServer,
}: Props) {
    const diff = wordDiff(myBaseText, serverPost.base_text);

    return (
        <Dialog
            open={open}
            onOpenChange={(next, details) => {
                // The conflict must be resolved by choosing a side. There is no
                // trigger, so `next` is only ever false here (an Escape / outside
                // -press dismissal attempt); explicitly cancel it. The parent
                // closes this dialog by resolving the conflict and unmounting.
                if (!next) {
                    details.cancel();
                }
            }}
        >
            <DialogContent className="max-w-2xl" showCloseButton={false}>
                <DialogHeader>
                    <DialogTitle className="text-[15px] font-semibold">
                        Someone else updated this post
                    </DialogTitle>
                    <DialogDescription className="text-[12.5px] text-muted-foreground">
                        Your draft is based on an older version. Pick a side, or
                        review the differences first.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid grid-cols-2 gap-3 rounded-md border border-border bg-muted/30 p-3 text-[12.5px] leading-relaxed">
                    <div>
                        <div className="mb-1 text-[10.5px] font-medium tracking-wider text-muted-foreground uppercase">
                            Your version
                        </div>
                        <p className="whitespace-pre-wrap">
                            {diff.map((d, i) =>
                                d.kind === 'removed' ? (
                                    <mark
                                        key={i}
                                        className="rounded bg-destructive/15 text-destructive"
                                    >
                                        {d.text}
                                    </mark>
                                ) : d.kind === 'equal' ? (
                                    <span key={i}>{d.text}</span>
                                ) : null,
                            )}
                        </p>
                    </div>
                    <div>
                        <div className="mb-1 text-[10.5px] font-medium tracking-wider text-muted-foreground uppercase">
                            Latest from server
                        </div>
                        <p className="whitespace-pre-wrap">
                            {diff.map((d, i) =>
                                d.kind === 'added' ? (
                                    <mark
                                        key={i}
                                        className="rounded bg-primary/15 text-primary"
                                    >
                                        {d.text}
                                    </mark>
                                ) : d.kind === 'equal' ? (
                                    <span key={i}>{d.text}</span>
                                ) : null,
                            )}
                        </p>
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={onUseServer}>
                        Use their version
                    </Button>
                    <Button onClick={onKeepMine}>Keep mine</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
