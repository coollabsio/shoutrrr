<?php

use App\Dto\Feedback\FeedbackReport;
use App\Enums\FeedbackType;
use App\Services\Feedback\FeedbackService;
use Illuminate\Support\Facades\Http;

const FEEDBACK_HOOK = 'https://discord.com/api/webhooks/1/tok';

function makeReport(array $overrides = []): FeedbackReport
{
    return new FeedbackReport(
        type: $overrides['type'] ?? FeedbackType::Bug,
        message: $overrides['message'] ?? 'It broke',
        url: $overrides['url'] ?? 'https://app.test/dashboard',
        browser: $overrides['browser'] ?? 'Mozilla/5.0',
        environment: $overrides['environment'] ?? 'production',
        userName: $overrides['userName'] ?? 'Ada',
        userEmail: $overrides['userEmail'] ?? 'ada@test.co',
        workspaceName: $overrides['workspaceName'] ?? 'Acme',
        workspaceId: $overrides['workspaceId'] ?? 'ws-123',
        subscriptionStatus: $overrides['subscriptionStatus'] ?? 'subscribed',
        screenshotBytes: $overrides['screenshotBytes'] ?? null,
        diagnosticsJson: $overrides['diagnosticsJson'] ?? null,
    );
}

beforeEach(function () {
    config(['feedback.enabled' => true, 'feedback.webhook_url' => FEEDBACK_HOOK]);
});

it('posts a JSON embed to the webhook when there is no screenshot', function () {
    Http::fake([FEEDBACK_HOOK => Http::response('', 204)]);

    app(FeedbackService::class)->send(makeReport(['message' => 'It broke']));

    Http::assertSent(function ($request) {
        $embed = $request['embeds'][0];

        return $request->url() === FEEDBACK_HOOK
            && $embed['description'] === 'It broke'
            && $embed['color'] === FeedbackType::Bug->color()
            && collect($embed['fields'])->contains(fn ($f) => $f['value'] === 'ada@test.co')
            && collect($embed['fields'])->contains(fn ($f) => str_contains($f['value'], 'Acme'));
    });
});

it('includes the environment as an embed field', function () {
    Http::fake([FEEDBACK_HOOK => Http::response('', 204)]);

    app(FeedbackService::class)->send(makeReport(['environment' => 'staging']));

    Http::assertSent(function ($request) {
        $field = collect($request['embeds'][0]['fields'])->firstWhere('name', 'Environment');

        return $field !== null && $field['value'] === 'staging';
    });
});

it('posts a multipart attachment when a screenshot is present', function () {
    Http::fake([FEEDBACK_HOOK => Http::response('', 204)]);

    app(FeedbackService::class)->send(makeReport(['screenshotBytes' => 'PNGBYTES']));

    Http::assertSent(function ($request) {
        $body = $request->body();

        return str_contains($body, 'name="files[0]"')
            && str_contains($body, 'name="payload_json"')
            && str_contains($body, 'attachment://screenshot.png');
    });
});

it('attaches diagnostics json as a file when present', function () {
    Http::fake([FEEDBACK_HOOK => Http::response('', 204)]);

    app(FeedbackService::class)->send(makeReport(['diagnosticsJson' => '{"logs":[]}']));

    Http::assertSent(function ($request) {
        $body = $request->body();

        return str_contains($body, 'name="files[0]"')
            && str_contains($body, 'diagnostics.json')
            && str_contains($body, 'name="payload_json"');
    });
});

it('attaches both the screenshot and diagnostics as separate files', function () {
    Http::fake([FEEDBACK_HOOK => Http::response('', 204)]);

    app(FeedbackService::class)->send(makeReport([
        'screenshotBytes' => 'PNGBYTES',
        'diagnosticsJson' => '{"logs":[]}',
    ]));

    Http::assertSent(function ($request) {
        $body = $request->body();

        return str_contains($body, 'name="files[0]"')
            && str_contains($body, 'screenshot.png')
            && str_contains($body, 'name="files[1]"')
            && str_contains($body, 'diagnostics.json');
    });
});

it('throws when the webhook responds with an error status', function () {
    Http::fake([FEEDBACK_HOOK => Http::response('nope', 500)]);

    app(FeedbackService::class)->send(makeReport());
})->throws(RuntimeException::class);

it('truncates an oversized url to fit the Discord field value limit', function () {
    Http::fake([FEEDBACK_HOOK => Http::response('', 204)]);

    $longUrl = 'https://app.test/dashboard?'.str_repeat('a', 2000);

    app(FeedbackService::class)->send(makeReport(['url' => $longUrl]));

    Http::assertSent(function ($request) {
        $field = collect($request['embeds'][0]['fields'])->firstWhere('name', 'Page');

        return $field !== null && mb_strlen($field['value']) <= 1024;
    });
});
