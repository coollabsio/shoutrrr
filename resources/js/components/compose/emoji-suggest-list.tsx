import type { EmojiMatch } from '@/lib/compose/emoji/types';
import { cn } from '@/lib/utils';

type Props = {
    matches: EmojiMatch[];
    activeIndex: number;
    onSelect: (match: EmojiMatch) => void;
};

/** Presentational `:shortcode` results. Focus stays in the editor; the plugin
 *  drives keyboard nav, so items use onMouseDown to avoid stealing focus. */
export default function EmojiSuggestList({
    matches,
    activeIndex,
    onSelect,
}: Props) {
    return (
        <div className="max-h-64 w-64 overflow-y-auto p-1">
            {matches.map((match, index) => (
                <button
                    key={`${match.shortcode}-${index}`}
                    type="button"
                    aria-label={match.label}
                    onMouseDown={(event) => {
                        event.preventDefault();
                        onSelect(match);
                    }}
                    className={cn(
                        'flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm',
                        index === activeIndex
                            ? 'bg-muted text-foreground'
                            : 'text-muted-foreground',
                    )}
                >
                    <span className="text-lg">{match.emoji}</span>
                    <span className="truncate">:{match.shortcode}:</span>
                </button>
            ))}
        </div>
    );
}
