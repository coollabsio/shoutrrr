<?php

declare(strict_types=1);

namespace App\Enums;

enum PostTargetStatus: string
{
    case Pending = 'pending';
    case Publishing = 'publishing';
    case Published = 'published';
    case Failed = 'failed';
    case Deleting = 'deleting';
    case Deleted = 'deleted';
}
