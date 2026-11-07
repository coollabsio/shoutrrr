<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A transport error is raw cURL text with hosts and paths: log it, and show the
 * user this message instead.
 */
final class ConnectionFailure
{
    public const string MESSAGE = 'Could not reach the platform.';

    public static function report(ConnectionException $e): string
    {
        Log::warning('platform.connection.failed', ['message' => $e->getMessage()]);

        return self::MESSAGE;
    }

    /**
     * For a Throwable of any type: only a connection error, direct or wrapped, is replaced.
     */
    public static function safeMessage(Throwable $e): string
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof ConnectionException) {
                return self::report($cause);
            }
        }

        return $e->getMessage();
    }
}
