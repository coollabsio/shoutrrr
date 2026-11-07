<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The platform accepted the reply but we could not record it. A retry would
 * post it twice, so the job fails at once and tells the user to check first.
 */
class ReplyMayHavePosted extends RuntimeException
{
    public const string USER_MESSAGE = 'The reply may have posted. Check the post before you send it again.';

    public function __construct(Throwable $previous)
    {
        parent::__construct('Reply posted but not recorded: '.$previous->getMessage(), previous: $previous);
    }
}
