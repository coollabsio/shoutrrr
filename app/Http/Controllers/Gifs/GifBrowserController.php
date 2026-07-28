<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gifs;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Gifs\GifItem;
use App\Services\Gifs\KlipyClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class GifBrowserController extends Controller
{
    public function __construct(private readonly KlipyClient $klipy) {}

    public function index(Request $request, string $catalog): JsonResponse
    {
        $query = $request->string('q')->trim()->limit(100, '')->toString();
        $page = max(1, min(50, $request->integer('page', 1)));
        $rating = (string) config('services.klipy.rating', 'pg-13');

        // Trending and popular searches repeat constantly across users; a short
        // TTL keeps the grid instant without serving stale content.
        $key = "gifs:{$catalog}:{$rating}:{$page}:".md5($query);

        try {
            $result = Cache::remember($key, 60, fn (): array => $this->encode(
                $this->klipy->browse($catalog, $query, $page)
            ));
        } catch (RuntimeException) {
            abort(502, 'GIF search is unavailable right now.');
        }

        return response()->json($result);
    }

    public function recent(Request $request, string $catalog): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $result = $this->klipy->recent($catalog, self::customerId($user), 1);
        } catch (RuntimeException) {
            // Recents are a nicety; an empty shelf beats an error toast.
            return response()->json(['items' => [], 'has_next' => false]);
        }

        return response()->json($this->encode($result));
    }

    /**
     * A stable, non-identifying per-user id for Klipy's recents and share
     * reporting. Derived from the app key, so it never leaves this deployment
     * and cannot be reversed to a user id.
     */
    public static function customerId(User $user): string
    {
        return substr(hash_hmac('sha256', (string) $user->id, (string) config('app.key')), 0, 32);
    }

    /**
     * @param  array{items: list<GifItem>, has_next: bool}  $result
     * @return array{items: list<array<string, mixed>>, has_next: bool}
     */
    private function encode(array $result): array
    {
        return [
            'items' => array_map(fn (GifItem $item): array => $item->toArray(), $result['items']),
            'has_next' => $result['has_next'],
        ];
    }
}
