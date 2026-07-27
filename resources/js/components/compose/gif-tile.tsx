import { Heart } from 'lucide-react';

import { cn } from '@/lib/utils';
import type { GifItem } from '@/types/gifs';

type Props = {
    item: GifItem;
    isFavorite: boolean;
    onSelect: (item: GifItem) => void;
    onToggleFavorite: (item: GifItem) => void;
};

/** Fallback used whenever a preview's dimensions aren't usable numbers. */
const DEFAULT_RATIO = 1;

/**
 * A stored favourite is only validated for a usable `url`/`mime` (see
 * `parseFavorites`), not for numeric `width`/`height` — so those can be
 * absent, non-numeric, or zero. Guard both operands and fall back to a
 * square box rather than letting `NaN`/`Infinity` reach CSS `aspect-ratio`,
 * which breaks layout for every tile that follows it in the column.
 */
function aspectRatioOf(item: GifItem): number {
    const { width, height } = item.preview;

    if (
        typeof width !== 'number' ||
        typeof height !== 'number' ||
        !Number.isFinite(width) ||
        !Number.isFinite(height) ||
        width <= 0 ||
        height <= 0
    ) {
        return DEFAULT_RATIO;
    }

    return width / height;
}

/**
 * One grid cell. The aspect-ratio box is sized from the item's own dimensions so
 * the masonry column does not reflow as images stream in, and the preview fades
 * in on load rather than popping.
 */
export function GifTile({
    item,
    isFavorite,
    onSelect,
    onToggleFavorite,
}: Props) {
    const ratio = aspectRatioOf(item);

    return (
        <div className="group relative">
            <button
                type="button"
                aria-label={`Insert ${item.title || 'GIF'}`}
                onClick={() => onSelect(item)}
                style={{ aspectRatio: String(ratio) }}
                className="w-full overflow-hidden rounded-lg bg-muted outline-hidden transition-[box-shadow] focus-visible:ring-2 focus-visible:ring-ring"
            >
                {item.catalog === 'clip' ? (
                    <div className="relative size-full">
                        <video
                            src={item.preview.url}
                            autoPlay
                            loop
                            muted
                            playsInline
                            className="size-full object-cover"
                        />
                        <span className="absolute right-1 bottom-1 rounded bg-black/60 px-1 py-0.5 text-[10px] font-medium text-white">
                            ▶
                        </span>
                    </div>
                ) : (
                    <img
                        src={item.preview.url}
                        alt={item.title}
                        loading="lazy"
                        onLoad={(event) =>
                            event.currentTarget.classList.remove('opacity-0')
                        }
                        className="size-full object-cover opacity-0 transition-opacity duration-100"
                    />
                )}
            </button>

            <button
                type="button"
                aria-label={
                    isFavorite
                        ? `Remove ${item.title || 'GIF'} from favourites`
                        : `Favourite ${item.title || 'GIF'}`
                }
                onClick={(event) => {
                    event.stopPropagation();
                    onToggleFavorite(item);
                }}
                className={cn(
                    'absolute top-1.5 right-1.5 flex size-6 items-center justify-center rounded-full bg-black/50 text-white backdrop-blur-sm transition-opacity',
                    'opacity-0 group-hover:opacity-100 focus-visible:opacity-100',
                    isFavorite && 'opacity-100',
                )}
            >
                <Heart
                    className={cn('size-3.5', isFavorite && 'fill-current')}
                    aria-hidden="true"
                />
            </button>
        </div>
    );
}
