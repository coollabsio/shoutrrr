<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\Platform;
use App\Models\WorkspaceMention;
use App\Support\LinkedInOrg;

class WorkspaceMentionService
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        // Nested rules must cover every platform key: `validated()` strips any
        // `handles.*` key without an explicit rule (excludeUnvalidatedArrayKeys),
        // which silently dropped Meta handles before these rules were derived
        // from the enum (issue #123).
        $handleRules = [];
        foreach ([...self::platformKeys(), 'linkedin_urn'] as $handleKey) {
            $handleRules['handles.'.$handleKey] = ['nullable', 'string', 'max:255'];
        }

        return [
            'name' => ['required', 'string', 'max:80', 'regex:/^@[A-Za-z0-9_.-]+$/'],
            'handles' => ['required', 'array'],
            ...$handleRules,
        ];
    }

    /**
     * Create or replace the workspace mention with this name.
     *
     * @param  array<string, string|null>  $handles
     */
    public function save(string $workspaceId, string $name, array $handles): WorkspaceMention
    {
        $normalized = [];
        foreach (self::platformKeys() as $platform) {
            $handle = trim((string) ($handles[$platform] ?? ''));
            if ($handle !== '') {
                $normalized[$platform] = $handle;
            }
        }

        $linkedinUrn = LinkedInOrg::normalizeUrn($handles['linkedin_urn'] ?? null);
        if ($linkedinUrn !== null) {
            $normalized['linkedin_urn'] = $linkedinUrn;
        }

        return WorkspaceMention::withoutGlobalScopes()->updateOrCreate(
            ['workspace_id' => $workspaceId, 'name' => $name],
            ['handles' => $normalized],
        );
    }

    /**
     * Agent-facing view. `post_mention` is the exact entry create_post and
     * update_post accept in `mentions`, with the id derived the same way the
     * composer derives it (mentionIdFromLabel in resources/js/lib/compose/mentions.ts).
     *
     * @return array{id: string, name: string, handles: array<string, string>, post_mention: array{id: string, label: string, handles: array<string, string>}}
     */
    public static function present(WorkspaceMention $mention): array
    {
        $postMentionId = (string) preg_replace('/[^a-z0-9_-]+/', '-', mb_strtolower(ltrim($mention->name, '@')));

        return [
            'id' => $mention->id,
            'name' => $mention->name,
            'handles' => $mention->handles,
            'post_mention' => [
                'id' => $postMentionId,
                'label' => $mention->name,
                'handles' => $mention->handles,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private static function platformKeys(): array
    {
        return array_map(static fn (Platform $platform): string => $platform->value, Platform::cases());
    }
}
