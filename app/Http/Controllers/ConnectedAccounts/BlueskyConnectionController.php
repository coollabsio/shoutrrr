<?php

declare(strict_types=1);

namespace App\Http\Controllers\ConnectedAccounts;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConnectedAccount\ConnectBlueskyRequest;
use App\Services\ConnectedAccounts\AccountConnectionService;
use App\Services\ConnectedAccounts\BlueskyConnector;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

class BlueskyConnectionController extends Controller
{
    public function __construct(
        private readonly BlueskyConnector $connector,
        private readonly AccountConnectionService $connections,
    ) {}

    public function store(ConnectBlueskyRequest $request): RedirectResponse
    {
        try {
            $data = $this->connector->connect(
                $request->string('identifier')->toString(),
                $request->string('app_password')->toString(),
                $request->input('pds_url'),
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        // Bluesky has no OAuth scopes to inspect, so `dm_enabled` is set from the
        // operator's self-declaration that the app password has DM access, made
        // at connect time via the "This app password has DM access" checkbox.
        $data = $data->withCapabilities([
            ...($data->capabilities ?? []),
            'dm_enabled' => $request->boolean('dm_access'),
        ]);

        $this->connections->store($data, $request->user());

        return redirect()->route('accounts.index')->with('success', 'Bluesky account connected.');
    }
}
