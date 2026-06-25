<?php

declare(strict_types=1);

namespace App\Enums;

enum ReplyStatus: string
{
    case Pending = 'pending';
    case Responded = 'responded';
    case Archived = 'archived';
}
