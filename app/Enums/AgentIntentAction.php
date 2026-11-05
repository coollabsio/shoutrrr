<?php

declare(strict_types=1);

namespace App\Enums;

enum AgentIntentAction: string
{
    case PublishNow = 'publish_now';
    case ScheduleAt = 'schedule_at';
}
