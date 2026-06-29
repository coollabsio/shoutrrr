<?php

declare(strict_types=1);

namespace App\Services\Ai;

use RuntimeException;

/**
 * Raised when an AI provider reports an error mid-stream (e.g. an invalid model
 * id, a rejected key, or a content refusal) so the controller can surface it to
 * the user instead of completing with a silently empty result.
 */
final class AiStreamException extends RuntimeException {}
