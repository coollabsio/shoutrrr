<?php

declare(strict_types=1);

namespace App\Services\ConnectedAccounts\TikTok;

use App\Enums\ErrorKind;
use Illuminate\Http\Client\Response;

/**
 * An error from TikTok's v2 API (`{"error": {"code", "message", "log_id"}}`).
 * Several Content Posting API errors arrive with HTTP 200, so the body's code
 * — not the status — decides what went wrong.
 *
 * @see https://developers.tiktok.com/doc/tiktok-api-v2-error-handling
 * @see https://developers.tiktok.com/doc/content-posting-api-reference-direct-post
 */
final readonly class TikTokApiError
{
    private const array MESSAGES = [
        'access_token_invalid' => 'TikTok rejected the access token. Reconnect the TikTok account.',
        'scope_not_authorized' => 'The TikTok account is missing a permission. Reconnect it and leave every permission switched on.',
        'rate_limit_exceeded' => 'TikTok is rate limiting requests. Try again in a minute.',
        'internal_error' => 'TikTok had an internal error.',
        'spam_risk_too_many_posts' => 'This TikTok account has reached its daily posting limit. Try again later.',
        'spam_risk_user_banned_from_posting' => 'TikTok is not letting this account post right now. Try again later.',
        'reached_active_user_cap' => 'This app has reached the number of TikTok creators it can post for today. Try again later.',
        'spam_risk_too_many_pending_share' => 'This TikTok account already has five drafts waiting in its inbox. Post or delete them in the TikTok app, then try again.',
        'unaudited_client_can_only_post_to_private_accounts' => 'Until TikTok audits this app, it can only post to private TikTok accounts, visible to "Only me".',
        'url_ownership_unverified' => 'TikTok could not confirm this app owns the media URL. Verify the media domain or URL prefix in the TikTok developer portal.',
        'privacy_level_option_mismatch' => 'The chosen privacy level is no longer offered for this TikTok account. Choose another one.',
        'app_version_check_failed' => "The creator's TikTok app is too old to receive photo drafts. Update TikTok and try again.",
    ];

    public function __construct(
        public string $code,
        public int $httpStatus,
        public ?string $detail = null,
    ) {}

    /**
     * The error carried by a response, or null when TikTok reported success.
     */
    public static function from(Response $response): ?self
    {
        $code = $response->json('error.code');
        $code = is_string($code) && $code !== '' ? $code : null;

        if ($response->successful() && ($code === null || $code === 'ok')) {
            return null;
        }

        $detail = $response->json('error.message');

        return new self(
            code: $code !== null && $code !== 'ok' ? $code : "http_{$response->status()}",
            httpStatus: $response->status(),
            detail: is_string($detail) && $detail !== '' ? $detail : null,
        );
    }

    public function kind(): ErrorKind
    {
        return match (true) {
            $this->code === 'rate_limit_exceeded', $this->httpStatus === 429 => ErrorKind::RateLimited,
            in_array($this->code, ['access_token_invalid', 'scope_not_authorized'], true), $this->httpStatus === 401 => ErrorKind::AuthExpired,
            $this->code === 'internal_error', $this->httpStatus >= 500 => ErrorKind::ServerError,
            default => ErrorKind::Validation,
        };
    }

    public function message(): string
    {
        $fallback = "TikTok rejected the request ({$this->code})".($this->detail !== null ? ": {$this->detail}" : '.');

        return self::MESSAGES[$this->code] ?? $fallback;
    }
}
