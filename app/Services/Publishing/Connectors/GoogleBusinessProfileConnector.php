<?php

declare(strict_types=1);

namespace App\Services\Publishing\Connectors;

use App\Dto\Publishing\PublishContext;
use App\Dto\Publishing\PublishResult;
use App\Enums\ErrorKind;
use App\Models\PostTarget;
use App\Services\Publishing\Contracts\PublishConnector;
use App\Support\RetryAfter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

class GoogleBusinessProfileConnector implements PublishConnector
{
    public function __construct(private readonly HttpFactory $http) {}

    public function publish(PublishContext $context): PublishResult
    {
        $token = (string) ($context->credentials['access_token'] ?? '');
        $location = $this->locationResource($context);

        if ($token === '') {
            return PublishResult::failure(ErrorKind::AuthExpired, 'Google Business Profile access token unavailable; reconnect the location.');
        }
        if ($location === null) {
            return PublishResult::failure(ErrorKind::Validation, 'Google Business Profile location capability is unavailable. Reconnect the location.');
        }
        if ($context->media !== []) {
            return PublishResult::failure(ErrorKind::Validation, 'Google Business Profile media is not supported in this release.');
        }
        if ($context->target->remote_id !== null) {
            return PublishResult::success($context->target->remote_ids ?? [$context->target->remote_id], $context->target->remote_metadata);
        }
        if (($context->target->remote_metadata['create_intent']['state'] ?? null) === 'outcome_unknown') {
            return PublishResult::failure(ErrorKind::Unknown, 'Google Business Profile create outcome is unknown. Verify the Local Post before retrying.');
        }

        try {
            $response = $this->http->withToken($token)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(20)
                ->post($this->baseUrl().'/'.$location.'/localPosts', $this->payload($context));
        } catch (ConnectionException $e) {
            return PublishResult::failure(ErrorKind::Network, 'Google Business Profile request could not be completed.', mayHaveCreatedRemote: true);
        }

        if ($response->failed()) {
            return $this->failure($response, $response->serverError());
        }

        $name = $response->json('name');
        if (! is_string($name) || $name === '') {
            return PublishResult::failure(ErrorKind::ServerError, 'Google Business Profile did not return a Local Post resource name.', $response->status(), $this->excerpt($response));
        }

        return PublishResult::success([$name], $this->metadata($response), $response->status());
    }

    /** @param array<string, mixed> $credentials */
    public function delete(PostTarget $target, array $credentials): void
    {
        $token = (string) ($credentials['access_token'] ?? '');
        $name = $target->remote_metadata['name'] ?? $target->remote_id;
        if ($token === '' || ! is_string($name) || $name === '') {
            throw new \RuntimeException('Google Business Profile deletion requires an access token and canonical Local Post name.');
        }

        $response = $this->http->withToken($token)->connectTimeout(5)->timeout(20)->delete($this->baseUrl().'/'.$name);
        if ($response->status() !== 404) {
            $response->throw();
        }
    }

    /** @param array<string, mixed> $credentials */
    public function fetchState(PostTarget $target, array $credentials): PublishResult
    {
        $token = (string) ($credentials['access_token'] ?? '');
        $name = $target->remote_metadata['name'] ?? $target->remote_id;
        if ($token === '' || ! is_string($name) || $name === '') {
            return PublishResult::failure(ErrorKind::AuthExpired, 'Google Business Profile access token or Local Post name is unavailable.');
        }

        try {
            $response = $this->http->withToken($token)->acceptJson()->connectTimeout(5)->timeout(20)->get($this->baseUrl().'/'.$name);
        } catch (ConnectionException) {
            return PublishResult::failure(ErrorKind::Network, 'Google Business Profile lifecycle request could not be completed.');
        }

        if ($response->failed()) {
            return $this->failure($response);
        }

        $name = $response->json('name');
        if (! is_string($name) || $name === '') {
            return PublishResult::failure(ErrorKind::ServerError, 'Google Business Profile did not return a Local Post resource name.', $response->status(), $this->excerpt($response));
        }

        return PublishResult::success([$name], $this->metadata($response), $response->status());
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.google_business_profile.base_url'), '/');
    }

    private function locationResource(PublishContext $context): ?string
    {
        $capabilities = $context->account->capabilities['google_business_profile'] ?? [];
        $location = $capabilities['locationResourceName'] ?? $capabilities['key'] ?? null;

        return is_string($location) && preg_match('#^accounts/[^/]+/locations/[^/]+$#', $location) === 1 ? $location : null;
    }

    /** @return array<string, mixed> */
    private function payload(PublishContext $context): array
    {
        $options = $context->target->provider_options['google_business_profile'] ?? [];
        $type = strtoupper((string) ($options['local_post_type'] ?? 'standard'));
        $payload = [
            'summary' => implode("\n", $context->segments),
            'languageCode' => $options['language'] ?? 'en',
            'topicType' => $type,
        ];
        if (filled($options['cta_type'] ?? null)) {
            $payload['callToAction'] = array_filter(['actionType' => $options['cta_type'], 'url' => $options['cta_type'] === 'CALL' ? null : ($options['cta_url'] ?? null)]);
        }
        if ($type === 'EVENT') {
            $payload['event'] = ['title' => $options['title'] ?? null, 'schedule' => ['startDateTime' => $options['start_at'] ?? null, 'endDateTime' => $options['end_at'] ?? null]];
        }
        if ($type === 'OFFER') {
            $payload['offer'] = array_filter(['couponCode' => $options['coupon_code'] ?? null, 'redeemOnlineUrl' => $options['redemption_url'] ?? null, 'termsConditions' => $options['terms'] ?? null]);
        }

        return $payload;
    }

    private function failure(Response $response, bool $mayHaveCreatedRemote = false): PublishResult
    {
        $status = $response->status();
        $kind = match (true) {
            $status === 429 => ErrorKind::RateLimited,
            $status === 401 => ErrorKind::AuthExpired,
            $status === 400 || $status === 403 || $status === 404 => ErrorKind::Validation,
            $status >= 500 => ErrorKind::ServerError,
            default => ErrorKind::Unknown,
        };

        return PublishResult::failure($kind, 'Google Business Profile rejected the Local Post request.', $status, $this->excerpt($response), RetryAfter::seconds($response), $mayHaveCreatedRemote);
    }

    /** @return array<string, mixed> */
    private function metadata(Response $response): array
    {
        return array_filter([
            'name' => $response->json('name'),
            'state' => $response->json('state'),
            'search_url' => $response->json('searchUrl'),
            'create_time' => $response->json('createTime'),
            'update_time' => $response->json('updateTime'),
            'accepted_at' => now()->toIso8601String(),
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function excerpt(Response $response): string
    {
        $error = $response->json('error');
        if (! is_array($error)) {
            return 'Google Business Profile returned an unstructured error response.';
        }

        return (string) json_encode(array_filter([
            'status' => $error['status'] ?? null,
            'reason' => data_get($error, 'details.0.reason'),
            'service' => data_get($error, 'details.0.@type'),
            'message' => isset($error['message']) ? Str::limit((string) $error['message'], 300) : null,
        ]), JSON_THROW_ON_ERROR);
    }
}
