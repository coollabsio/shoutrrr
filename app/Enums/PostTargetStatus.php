<?php

declare(strict_types=1);

namespace App\Enums;

enum PostTargetStatus: string
{
    case Pending = 'pending';
    case Publishing = 'publishing';
    case Published = 'published';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Deleting = 'deleting';
    case Deleted = 'deleted';

    /**
     * Whether a manual retry may re-dispatch this target: a hard failure or a
     * target that was skipped (e.g. its platform was frozen instance-wide) and
     * can now be re-attempted.
     */
    public function isRetryable(): bool
    {
        return in_array($this, [self::Failed, self::Skipped], true);
    }
}
