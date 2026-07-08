<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AccountSet;
use App\Models\ConnectedAccount;
use App\Support\CursorPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

class AccountSetsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = AccountSet::query()
            ->with('accounts:id')
            ->orderBy('id', 'desc')
            ->cursorPaginate($validated['per_page'] ?? 25)
            ->through(fn (AccountSet $set): array => [
                'id' => $set->id,
                'name' => $set->name,
                'connected_account_ids' => $set->accounts->pluck('id')->all(),
            ]);

        return response()->json(CursorPage::make($paginator));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', AccountSet::class);
        $workspaceId = (string) Context::get('workspace_id');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'connected_account_ids' => ['array'],
            'connected_account_ids.*' => ['string'],
        ]);

        $set = AccountSet::create(['workspace_id' => $workspaceId, 'name' => $validated['name']]);
        $set->accounts()->sync($this->scopedAccountIds($workspaceId, $validated['connected_account_ids'] ?? []));

        return response()->json($this->view($set), 201);
    }

    public function update(Request $request, string $set): JsonResponse
    {
        $model = AccountSet::query()->whereKey($set)
            ->firstOr(fn () => abort(404, 'No account set with that id exists in this workspace.'));
        $this->authorize('update', $model);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'connected_account_ids' => ['array'],
            'connected_account_ids.*' => ['string'],
        ]);

        $model->update(['name' => $validated['name']]);

        // PATCH semantics: only touch membership when the client actually sent
        // connected_account_ids. Omitting it leaves existing members intact;
        // sending an empty array explicitly clears them.
        if ($request->has('connected_account_ids')) {
            $model->accounts()->sync($this->scopedAccountIds($model->workspace_id, $validated['connected_account_ids']));
        }

        return response()->json($this->view($model->fresh()));
    }

    public function destroy(string $set): JsonResponse
    {
        $model = AccountSet::query()->whereKey($set)
            ->firstOr(fn () => abort(404, 'No account set with that id exists in this workspace.'));
        $this->authorize('delete', $model);

        $id = $model->id;
        $model->delete();

        return response()->json(['deleted' => true, 'id' => $id]);
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function scopedAccountIds(string $workspaceId, array $ids): array
    {
        return array_values(
            ConnectedAccount::withoutGlobalScope('workspace')
                ->where('workspace_id', $workspaceId)
                ->whereIn('id', $ids)
                ->pluck('id')
                ->map(fn (mixed $id): string => (string) $id)
                ->all()
        );
    }

    /**
     * @return array{id: string, name: string, connected_account_ids: list<string>}
     */
    private function view(AccountSet $set): array
    {
        return [
            'id' => $set->id,
            'name' => $set->name,
            'connected_account_ids' => array_values(
                $set->accounts()->pluck('connected_accounts.id')->map(fn (mixed $id): string => (string) $id)->all()
            ),
        ];
    }
}
