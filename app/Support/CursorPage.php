<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Pagination\CursorPaginator;

final class CursorPage
{
    /**
     * @param  CursorPaginator<array-key, mixed>  $paginator
     * @return array{data: array<int, mixed>, pagination: array{per_page: int, next_cursor: ?string, prev_cursor: ?string, has_more: bool}}
     */
    public static function make(CursorPaginator $paginator): array
    {
        return [
            'data' => $paginator->items(),
            'pagination' => [
                'per_page' => $paginator->perPage(),
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'prev_cursor' => $paginator->previousCursor()?->encode(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ];
    }
}
