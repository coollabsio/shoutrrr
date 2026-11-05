<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\PostFormat;
use App\Models\ConnectedAccount;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validation rules and MCP schema fragments for the post-composition inputs
 * shared by the agent surfaces (MCP tools, API v1), so each one accepts and
 * advertises the same shape as the Classic composer.
 */
final class PostComposerInput
{
    /**
     * @return array<string, mixed>
     */
    public static function contentRules(): array
    {
        return [
            'segments' => ['array'],
            'segments.*' => ['string'],
            'mentions' => ['array'],
            'mentions.*.id' => ['required', 'string'],
            'mentions.*.label' => ['required', 'string'],
            'mentions.*.handles' => ['array'],
            'mentions.*.handles.x' => ['nullable', 'string'],
            'mentions.*.handles.bluesky' => ['nullable', 'string'],
            'mentions.*.handles.linkedin' => ['nullable', 'string'],
            'mentions.*.handles.linkedin_urn' => ['nullable', 'string', 'max:255'],
            'media_ids' => ['array'],
            'media_ids.*' => ['string'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function targetRules(): array
    {
        return [
            'targets' => ['array'],
            'targets.*.connected_account_id' => ['required', 'string'],
            'targets.*.auto_split' => ['boolean'],
            'targets.*.format' => ['nullable', Rule::enum(PostFormat::class)],
            'targets.*.content_override' => ['nullable', 'array'],
            'targets.*.content_override.text' => ['nullable', 'string'],
            'targets.*.content_override.media_ids' => ['array'],
            'targets.*.content_override.media_ids.*' => ['string'],
        ];
    }

    /**
     * Reject a per-target `format` the target's platform does not offer (e.g.
     * Reels on X), the same set the web composer exposes via PostFormat::forPlatform.
     * Accounts resolve through the workspace global scope.
     *
     * @param  array<int, array{connected_account_id: string, format?: string|null}>  $targets
     *
     * @throws ValidationException
     */
    public static function ensureFormatsSupported(array $targets): void
    {
        $accounts = ConnectedAccount::query()
            ->whereKey(array_column($targets, 'connected_account_id'))
            ->get()
            ->keyBy('id');
        $errors = [];

        foreach ($targets as $index => $target) {
            $format = PostFormat::tryFrom((string) ($target['format'] ?? ''));

            if (! $format instanceof PostFormat) {
                continue;
            }

            $account = $accounts->get($target['connected_account_id']);

            if (! $account instanceof ConnectedAccount) {
                $errors["targets.{$index}.connected_account_id"] = 'No connected account with that id exists in this workspace.';

                continue;
            }

            if (! in_array($format, PostFormat::forPlatform($account->platform), true)) {
                $errors["targets.{$index}.format"] = "The {$format->value} format is not available on {$account->platform->value}.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @return array<string, Type>
     */
    public static function contentSchema(JsonSchema $schema): array
    {
        return [
            'segments' => $schema->array()->items($schema->string())
                ->description('Thread segments in order, one post per segment. Takes precedence over the single-text field when non-empty.'),
            'mentions' => $schema->array()->items($schema->object([
                'id' => $schema->string()->description('Placeholder id referenced as {{mention:id}} in the text.')->required(),
                'label' => $schema->string()->description('Fallback text when a platform has no handle.')->required(),
                'handles' => $schema->object([
                    'x' => $schema->string(),
                    'bluesky' => $schema->string(),
                    'linkedin' => $schema->string(),
                    'linkedin_urn' => $schema->string()->description('LinkedIn organization URN, for a real company tag.'),
                ])->description('Per-platform handle.'),
            ]))->description('Mention placeholders with per-platform handles.'),
            'media_ids' => $schema->array()->items($schema->string())
                ->description('Ordered media ids to attach (from add_post_media, add_post_video, or attach_gif).'),
        ];
    }

    public static function targetsSchema(JsonSchema $schema): Type
    {
        return $schema->array()->items($schema->object([
            'connected_account_id' => $schema->string()->description('Connected account the settings apply to. Must be part of the destination.')->required(),
            'auto_split' => $schema->boolean()->description('Split text that exceeds the platform limit into a thread (default true).'),
            'format' => $schema->string()->enum(array_map(static fn (PostFormat $f): string => $f->value, PostFormat::cases()))
                ->description('Post format. reels and story are only available on Instagram and Facebook.'),
            'content_override' => $schema->object([
                'text' => $schema->string()->description('Replacement text for this account only.'),
                'media_ids' => $schema->array()->items($schema->string())->description('Replacement media for this account only.'),
            ])->nullable()->description('Per-account content override. Send null to clear it.'),
        ]))->description('Per-account settings: auto-split, format, and content overrides.');
    }
}
