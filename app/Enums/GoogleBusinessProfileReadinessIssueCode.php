<?php

declare(strict_types=1);

namespace App\Enums;

enum GoogleBusinessProfileReadinessIssueCode: string
{
    case ApiDisabled = 'api_disabled';
    case PermissionDenied = 'permission_denied';
    case QuotaExceeded = 'quota_exceeded';
    case MalformedResponse = 'malformed_response';
    case NetworkFailure = 'network_failure';
    case ZeroLocations = 'zero_locations';
    case IneligibleLocation = 'ineligible_location';
    case UnknownGoogleError = 'unknown_google_error';
}
