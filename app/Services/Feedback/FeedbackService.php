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

        if ($report->screenshotBytes === null) {
            $response = $this->http()->post($webhookUrl, ['embeds' => [$embed]]);
        } else {
            $embed['image'] = ['url' => 'attachment://screenshot.png'];

            $response = $this->http()
                ->attach('files[0]', $report->screenshotBytes, 'screenshot.png')
                ->post($webhookUrl, [
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
            'description' => $report->message,
            'color' => $report->type->color(),
            'fields' => [
                ['name' => 'Workspace', 'value' => "{$report->workspaceName} (`{$report->workspaceId}`)", 'inline' => true],
                ['name' => "User ({$report->userName})", 'value' => $report->userEmail, 'inline' => true],
                ['name' => 'Subscription', 'value' => $report->subscriptionStatus, 'inline' => true],
                ['name' => 'Page', 'value' => $report->url, 'inline' => false],
                ['name' => 'Browser', 'value' => $report->browser, 'inline' => false],
            ],
        ];
    }

    private function http(): PendingRequest
    {
        return app(HttpFactory::class)->timeout(15)->connectTimeout(5)->acceptJson();
    }
}
