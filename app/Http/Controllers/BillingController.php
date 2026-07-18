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
use Stripe\Exception\InvalidRequestException;

class BillingController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless(config('subscriptions.enabled'), 404);

        $workspace = $this->currentWorkspace($request);

        abort_unless($workspace instanceof Workspace, 404);

        $this->authorizeManageBilling($request, $workspace);

        $subscribed = $workspace->subscribed('default');
        $subscriptionGate = app(WorkspaceSubscriptionGate::class);
        $remainingBudget = $subscriptionGate->remainingXBudgetMicrousd($workspace);

        return Inertia::render('settings/workspace/subscription', [
            'subscribed' => $subscribed,
            'monthlyPrice' => (int) config('subscriptions.monthly_price_cents'),
            'monthlyXBudgetMicrousd' => $subscriptionGate->monthlyXBudgetMicrousd($workspace),
            'monthlyXBudgetUsedMicrousd' => $subscriptionGate->currentXCostMicrousd($workspace),
            'monthlyXBudgetRemainingMicrousd' => $remainingBudget === PHP_INT_MAX ? null : $remainingBudget,
            'canManageSubscription' => $subscribed,
            'canAccessPortal' => $workspace->hasStripeId(),
        ]);
    }

    public function checkout(Request $request): RedirectResponse
    {
        abort_unless(config('subscriptions.enabled'), 404);

        $workspace = $this->currentWorkspace($request);
        $user = $request->user();

        abort_unless($user instanceof User && $workspace instanceof Workspace, 404);

        $this->authorizeManageBilling($request, $workspace);

        if ($workspace->subscribed('default')) {
            return back()->with('error', 'This workspace already has an active subscription.');
        }

        $priceId = $this->configuredPriceId();

        if ($priceId === null) {
            return back()->with('error', 'Configure STRIPE_SUBSCRIPTION_PRICE_ID before starting checkout.');
        }

        $hadStripeCustomer = $workspace->hasStripeId();
        $customerOptions = [
            'email' => $user->email,
            'name' => $user->name,
        ];

        try {
            if ($hadStripeCustomer) {
                try {
                    $workspace->updateStripeCustomer($customerOptions);
                } catch (InvalidRequestException $e) {
                    // The stored customer no longer exists in Stripe (deleted in the
                    // dashboard, or a test->live key switch). Clear the stale id so a
                    // fresh customer is created instead of failing forever.
                    if (! str_contains(strtolower($e->getMessage()), 'no such customer')) {
                        throw $e;
                    }

                    $this->forgetIncompleteStripeCustomer($workspace);
                    $hadStripeCustomer = false;
                }
            }

            return $workspace
                ->newSubscription('default', $priceId)
                ->allowPromotionCodes()
                ->collectTaxIds()
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

        abort_unless($workspace instanceof Workspace, 404);

        $this->authorizeManageBilling($request, $workspace);

        // Gate on the Stripe customer, not an active subscription: a past_due or
        // expired customer still needs the portal to fix their payment method and
        // download invoices.
        abort_unless($workspace->hasStripeId(), 404);

        return $workspace->redirectToBillingPortal(route('billing.index'));
    }

    /**
     * Billing exposes the Stripe-hosted portal, which can cancel the subscription,
     * swap the payment method, and download past invoices. Only owners and admins
     * may reach any billing action.
     */
    private function authorizeManageBilling(Request $request, Workspace $workspace): void
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User && $user->hasAllPermissions(['workspace.billing.manage'], $workspace->id),
            403,
        );
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
