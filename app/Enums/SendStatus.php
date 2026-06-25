<?php

declare(strict_types=1);

namespace App\Enums;

enum SendStatus: string
{
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
}
