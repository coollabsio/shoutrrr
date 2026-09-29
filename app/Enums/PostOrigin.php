<?php

declare(strict_types=1);

namespace App\Enums;

enum PostOrigin: string
{
    case Composer = 'composer';
    case Sync = 'sync';
    case External = 'external';
}
