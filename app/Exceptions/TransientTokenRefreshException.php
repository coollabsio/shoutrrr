<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * A token refresh that failed for a transient reason (provider 429/5xx or a
 * connection timeout). The account is left Active and untouched so the next
 * refresh attempt retries; callers should back off rather than flip the
 * account to needs-attention or report the failure as an error.
 */
class TransientTokenRefreshException extends TokenRefreshException {}
