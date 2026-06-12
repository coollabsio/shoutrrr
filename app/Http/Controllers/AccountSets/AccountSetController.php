<?php

declare(strict_types=1);

namespace App\Http\Controllers\AccountSets;

use App\Http\Controllers\Controller;
use App\Http\Requests\AccountSet\StoreAccountSetRequest;
use App\Http\Requests\AccountSet\UpdateAccountSetRequest;
use App\Models\AccountSet;
use App\Models\ConnectedAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class AccountSetController extends Controller
{
    public function store(StoreAccountSetRequest $request): JsonResponse
    {
        $set = AccountSet::create([
            'workspace_id' => $request->user()->current_workspace_id,
            'name' => $request->validated('name'),
        ]);

        $set->accounts()->sync($this->scopedAccountIds($request->user()->current_workspace_id, $request->validated('connected_account_ids', [])));

        return response()->json(['account_set' => $this->view($set)], 201);
    }

    public function update(UpdateAccountSetRequest $request, AccountSet $accountSet): JsonResponse
    {
        $accountSet->update(['name' => $request->validated('name')]);
        $accountSet->accounts()->sync($this->scopedAccountIds($accountSet->workspace_id, $request->validated('connected_account_ids', [])));

        return response()->json(['account_set' => $this->view($accountSet->fresh())]);
    }

    public function destroy(AccountSet $accountSet): RedirectResponse
    {
        $accountSet->delete();

        return back()->with('success', 'Set deleted.');
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function scopedAccountIds(string $workspaceId, array $ids): array
    {
        return array_values(
            ConnectedAccount::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->whereIn('id', $ids)
                ->pluck('id')
                ->map(fn (mixed $id): string => (string) $id)
                ->all()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function view(AccountSet $set): array
    {
        return [
            'id' => $set->id,
            'name' => $set->name,
            'connected_account_ids' => $set->accounts()->pluck('connected_accounts.id')->all(),
        ];
    }
}
