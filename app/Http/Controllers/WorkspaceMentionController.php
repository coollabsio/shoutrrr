<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\WorkspaceMention;
use App\Services\Workspace\WorkspaceMentionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceMentionController extends Controller
{
    public function store(Request $request, WorkspaceMentionService $mentions): JsonResponse
    {
        $validated = $request->validate(WorkspaceMentionService::rules());

        $mention = $mentions->save(
            $request->user()->current_workspace_id,
            $validated['name'],
            $validated['handles'],
        );

        return response()->json(['mention' => self::view($mention)]);
    }

    public function destroy(Request $request, string $workspaceMention): JsonResponse
    {
        WorkspaceMention::withoutGlobalScopes()
            ->where('workspace_id', $request->user()->current_workspace_id)
            ->findOrFail($workspaceMention)
            ->delete();

        return response()->json(['deleted' => true]);
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
