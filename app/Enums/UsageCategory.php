<?php

declare(strict_types=1);

namespace App\Enums;

enum UsageCategory: string
{
    case Ai = 'ai';
    case Publish = 'publish';
    case ExternalApi = 'external_api';
    case ApiRequest = 'api_request';
}
