<?php

declare(strict_types=1);

namespace App\Services\Gifs;

/**
 * A vendor-free browse result. Everything the frontend sees comes from here, so
 * a change in Klipy's own field names never reaches beyond KlipyClient.
 */
readonly class GifItem
{
    /**
     * @param  list<GifVariant>  $variants  Ordered largest → smallest.
     */
    public function __construct(
        public string $id,
        public string $slug,
        public string $catalog,
        public string $title,
        public GifVariant $preview,
        public array $variants,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'catalog' => $this->catalog,
            'title' => $this->title,
            'preview' => $this->preview->toArray(),
            'variants' => array_map(fn (GifVariant $v): array => $v->toArray(), $this->variants),
        ];
    }
}
