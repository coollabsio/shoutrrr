<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SyncPipeline;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Sync\SyncPipelineService;
use App\Support\CursorPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

/**
 * Sync pipelines copy every post the source account publishes to up to three
 * destination accounts. All endpoints need the workspace.settings.manage
 * permission.
 */
class SyncPipelinesController extends Controller
{
    public function __construct(private readonly SyncPipelineService $pipelines) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = SyncPipeline::query()
            ->with('destinations:id')
            ->orderBy('id', 'desc')
            ->cursorPaginate($validated['per_page'] ?? 25)
            ->through(fn (SyncPipeline $pipeline): array => $this->pipelines->payload($pipeline));

        return response()->json(CursorPage::make($paginator));
    }

    /**
     * Fails with 422 when the plan's pipeline limit is reached. With
     * track_source, the source is also watched for posts published outside
     * Shoutrrr when its platform and plan allow it; tracked_source reports
     * whether that happened.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->authorizeManage($request);
        $workspace = Workspace::query()->findOrFail((string) Context::get('workspace_id'));

        /** @var array{name: string, enabled?: bool, source_connected_account_id: string, destination_connected_account_ids: list<string>, track_source?: bool} $validated */
        $validated = $request->validate($this->pipelines->createRules((string) Context::get('workspace_id')));

        $result = $this->pipelines->create($workspace, $user, $validated);

        return response()->json([
            ...$this->pipelines->payload($result['pipeline']),
            'tracked_source' => $result['tracked_source'],
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->authorizeManage($request);
        $pipeline = $this->findPipelineOrFail($id);

        /** @var array{name?: string, enabled?: bool, source_connected_account_id?: string, destination_connected_account_ids?: list<string>} $validated */
        $validated = $request->validate($this->pipelines->updateRules((string) Context::get('workspace_id')));

        return response()->json($this->pipelines->payload($this->pipelines->update($pipeline, $validated)));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->authorizeManage($request);
        $pipeline = $this->findPipelineOrFail($id);

        $pipeline->delete();

        return response()->json(['deleted' => true, 'id' => $pipeline->id]);
    }

    private function findPipelineOrFail(string $id): SyncPipeline
    {
        return SyncPipeline::query()->whereKey($id)
            ->firstOr(fn () => abort(404, 'No sync pipeline with that id exists in this workspace.'));
    }

    private function authorizeManage(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasAllPermissions(['workspace.settings.manage'], Context::get('workspace_id')), 403, 'This action is unauthorized.');

        return $user;
    }
}
