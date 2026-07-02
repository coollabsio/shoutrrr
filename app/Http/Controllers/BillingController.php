<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\WorkspaceSubscriptionGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Stripe\Exception\ApiErrorException;

class BillingController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless(config('subscriptions.enabled'), 404);

        $workspace = $this->currentWorkspace($request);

        abort_unless($workspace instanceof Workspace, 404);

        $subscribed = $workspace->subscribed('default');
        $subscriptionGate = app(WorkspaceSubscriptionGate::class);

        return Inertia::render('settings/workspace/subscription', [
            'subscribed' => $subscribed,
            'monthlyPrice' => (int) config('subscriptions.monthly_price_cents'),
            'monthlyXPostLimit' => $subscriptionGate->monthlyXPostLimit(),
            'monthlyXPostUsed' => $subscriptionGate->currentXPostUsage($workspace),
            'monthlyXPostRemaining' => $subscriptionGate->remainingXPosts($workspace),
            'canManageSubscription' => $subscribed,
        ]);
    }

    public function checkout(Request $request): RedirectResponse
    {
        abort_unless(config('subscriptions.enabled'), 404);

        $workspace = $this->currentWorkspace($request);
        $user = $request->user();

        abort_unless($user instanceof User && $workspace instanceof Workspace, 404);

        $priceId = $this->configuredPriceId();

        if ($priceId === null) {
            return back()->with('error', 'Configure STRIPE_SUBSCRIPTION_PRICE_ID before starting checkout.');
        }

        $hadStripeCustomer = $workspace->hasStripeId();
        $customerOptions = [
            'email' => $user->email,
            'name' => $workspace->name,
        ];

        try {
            if ($hadStripeCustomer) {
                $workspace->updateStripeCustomer($customerOptions);
            }

            return $workspace
                ->newSubscription('default', $priceId)
                ->checkout([
                    'success_url' => route('billing.index').'?billing=success',
                    'cancel_url' => route('billing.index').'?billing=cancelled',
                    'customer_update' => ['address' => 'auto', 'name' => 'auto'],
                    'metadata' => [
                        'workspace_id' => $workspace->id,
                        'workspace_name' => $workspace->name,
                    ],
                ], $customerOptions)
                ->redirect();
        } catch (ApiErrorException) {
            if (! $hadStripeCustomer) {
                $this->forgetIncompleteStripeCustomer($workspace);
            }

            return back()->with('error', 'Unable to start Stripe checkout. Check STRIPE_SUBSCRIPTION_PRICE_ID and Stripe test mode.');
        }
    }

    public function portal(Request $request): RedirectResponse
    {
        abort_unless(config('subscriptions.enabled'), 404);

        $workspace = $this->currentWorkspace($request);

        abort_unless($workspace instanceof Workspace && $workspace->subscribed('default'), 404);

        return $workspace->redirectToBillingPortal(route('billing.index'));
    }

    private function configuredPriceId(): ?string
    {
        $priceId = (string) config('subscriptions.stripe_price_id');

        if ($priceId === '' || $priceId === 'price_shoutrrr_monthly_test' || str_starts_with($priceId, 'price_your_')) {
            return null;
        }

        return $priceId;
    }

    private function forgetIncompleteStripeCustomer(Workspace $workspace): void
    {
        if ($workspace->subscribed('default')) {
            return;
        }

        $workspace->forceFill([
            'stripe_id' => null,
            'pm_type' => null,
            'pm_last_four' => null,
            'trial_ends_at' => null,
        ])->save();
    }

    private function currentWorkspace(Request $request): ?Workspace
    {
        $workspaceId = $request->user()?->current_workspace_id;

        if ($workspaceId === null) {
            return null;
        }

        return Workspace::query()->whereKey($workspaceId)->first();
    }
}
