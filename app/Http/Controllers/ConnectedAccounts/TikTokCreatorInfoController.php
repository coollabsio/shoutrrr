<?php

declare(strict_types=1);

namespace App\Http\Controllers\ConnectedAccounts;

use App\Enums\Platform;
use App\Exceptions\TokenRefreshException;
use App\Exceptions\TransientTokenRefreshException;
use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Services\ConnectedAccounts\TikTok\TikTokApiException;
use App\Services\ConnectedAccounts\TikTok\TikTokCreatorInfoClient;
use App\Services\Publishing\TokenManager;
use App\Support\ConnectionFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Live creator info for the composer's TikTok settings. TikTok's Direct Post
 * guidelines require the privacy options, interaction toggles and creator
 * name to come from a fresh query each time the post is composed, and require
 * the client to stop when the creator cannot post right now.
 */
class TikTokCreatorInfoController extends Controller
{
    public function __invoke(Request $request, ConnectedAccount $account, TokenManager $tokens, TikTokCreatorInfoClient $client): JsonResponse
    {
        $request->user()->can('create', Post::class) ?: abort(403);
        abort_unless($account->platform === Platform::TikTok, 404);

        try {
            $creator = $client->query($account, (string) ($tokens->fresh($account)['access_token'] ?? ''));
        } catch (TikTokApiException $exception) {
            return $this->unavailable($exception->getMessage());
        } catch (TransientTokenRefreshException) {
            return $this->unavailable('TikTok could not be reached. Try again in a moment.');
        } catch (TokenRefreshException) {
            return $this->unavailable('TikTok needs you to reconnect this account.');
        } catch (ConnectionException $exception) {
            return $this->unavailable(ConnectionFailure::report($exception));
        }

        return response()->json(['available' => true, 'creator' => $creator->toArray()]);
    }

    private function unavailable(string $message): JsonResponse
    {
        return response()->json(['available' => false, 'message' => $message]);
    }
}
