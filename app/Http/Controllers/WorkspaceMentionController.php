<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Models\WorkspaceMention;
use App\Support\LinkedInOrg;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceMentionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80', 'regex:/^@[A-Za-z0-9_.-]+$/'],
            'handles' => ['required', 'array'],
            'handles.x' => ['nullable', 'string', 'max:255'],
            'handles.bluesky' => ['nullable', 'string', 'max:255'],
            'handles.linkedin' => ['nullable', 'string', 'max:255'],
            'handles.linkedin_urn' => ['nullable', 'string', 'max:255'],
        ]);

        $allowedPlatforms = array_map(
            static fn (Platform $platform): string => $platform->value,
            Platform::cases(),
        );
        $handles = [];
        foreach ($allowedPlatforms as $platform) {
            $handle = trim((string) ($validated['handles'][$platform] ?? ''));
            if ($handle !== '') {
                $handles[$platform] = $handle;
            }
        }

        $linkedinUrn = LinkedInOrg::normalizeUrn($validated['handles']['linkedin_urn'] ?? null);
        if ($linkedinUrn !== null) {
            $handles['linkedin_urn'] = $linkedinUrn;
        }

        $mention = WorkspaceMention::withoutGlobalScopes()->updateOrCreate(
            [
                'workspace_id' => $request->user()->current_workspace_id,
                'name' => $validated['name'],
            ],
            ['handles' => $handles],
        );

        return response()->json(['mention' => self::view($mention)]);
    }

    /**
     * @return array{id: string, name: string, handles: array<string, string>}
     */
    public static function view(WorkspaceMention $mention): array
    {
        return [
            'id' => $mention->id,
            'name' => $mention->name,
            'handles' => $mention->handles,
        ];
    }
}
