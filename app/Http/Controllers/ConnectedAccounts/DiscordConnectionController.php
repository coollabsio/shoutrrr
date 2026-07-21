<?php

declare(strict_types=1);

namespace App\Http\Controllers\ConnectedAccounts;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConnectedAccount\ConnectDiscordRequest;
use App\Services\ConnectedAccounts\AccountConnectionService;
use App\Services\ConnectedAccounts\DiscordConnector;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

class DiscordConnectionController extends Controller
{
    public function __construct(
        private readonly DiscordConnector $connector,
        private readonly AccountConnectionService $connections,
    ) {}

    public function store(ConnectDiscordRequest $request): RedirectResponse
    {
        try {
            $data = $this->connector->connect($request->string('webhook_url')->toString());
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $this->connections->store($data, $request->user());

        return redirect()->route('accounts.index')->with('success', 'Discord webhook connected.');
    }
}
