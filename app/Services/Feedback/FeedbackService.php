<?php

declare(strict_types=1);

namespace App\Services\Feedback;

use App\Dto\Feedback\FeedbackReport;
use App\Support\FeedbackConfig;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;

class FeedbackService
{
    public function send(FeedbackReport $report): void
    {
        $webhookUrl = FeedbackConfig::webhookUrl();

        if ($webhookUrl === null) {
            throw new RuntimeException('Feedback webhook URL is not configured.');
        }

        $embed = $this->buildEmbed($report);

        /** @var list<array{name: string, bytes: string}> $attachments */
        $attachments = [];

        if ($report->screenshotBytes !== null) {
            $embed['image'] = ['url' => 'attachment://screenshot.png'];
            $attachments[] = ['name' => 'screenshot.png', 'bytes' => $report->screenshotBytes];
        }

        if ($report->diagnosticsJson !== null) {
            $attachments[] = ['name' => 'diagnostics.json', 'bytes' => $report->diagnosticsJson];
        }

        if ($attachments === []) {
            $response = $this->http()->post($webhookUrl, ['embeds' => [$embed]]);
        } else {
            $request = $this->http();

            foreach ($attachments as $index => $attachment) {
                $request = $request->attach("files[{$index}]", $attachment['bytes'], $attachment['name']);
            }

            $response = $request->post($webhookUrl, [
                'payload_json' => json_encode(['embeds' => [$embed]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ]);
        }

        if ($response->failed()) {
            throw new RuntimeException("Discord webhook rejected feedback: {$response->status()}");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEmbed(FeedbackReport $report): array
    {
        return [
            'title' => $report->type->label(),
            'description' => $this->truncate($report->message, 4096),
            'color' => $report->type->color(),
            'fields' => [
                ['name' => 'Workspace', 'value' => $this->truncate("{$report->workspaceName} (`{$report->workspaceId}`)", 1024), 'inline' => true],
                ['name' => "User ({$report->userName})", 'value' => $this->truncate($report->userEmail, 1024), 'inline' => true],
                ['name' => 'Subscription', 'value' => $this->truncate($report->subscriptionStatus, 1024), 'inline' => true],
                ['name' => 'Environment', 'value' => $this->truncate($report->environment, 1024), 'inline' => true],
                ['name' => 'Page', 'value' => $this->truncate($report->url, 1024), 'inline' => false],
                ['name' => 'Browser', 'value' => $this->truncate($report->browser, 1024), 'inline' => false],
            ],
        ];
    }

    /**
     * Discord rejects embeds whose field values or description exceed its
     * length limits (1024 for fields, 4096 for description); truncate
     * defensively so an oversized value never turns into a lost report.
     */
    private function truncate(string $value, int $limit): string
    {
        return mb_strlen($value) <= $limit ? $value : mb_substr($value, 0, $limit - 1).'…';
    }

    private function http(): PendingRequest
    {
        return app(HttpFactory::class)->timeout(15)->connectTimeout(5)->acceptJson();
    }
}
