<?php

namespace App\Http\Controllers;

use App\Models\CustomerAddress;
use App\Models\PlannedOrder;
use App\Models\Purchase;
use App\Models\Subscription;
use Carbon\Carbon;

/**
 * Subscription details page (read only): customer, app account, addresses,
 * pets, purchases and deliveries.
 */
class SubscriptionDetailsController extends Controller
{
    public function show(Subscription $subscription)
    {
        $user = auth()->user();
        abort_unless($user && $user->hasPermission('subscriptions.show'), 403);

        $subscription->load([
            'customerAccount.addresses.zone.province',
            'deliveryAddress.zone.province',
            'animals',
        ]);

        // Contact block = default address (slot included), like the daily report
        CustomerAddress::applyToSubscription($subscription);

        $today = Carbon::today()->toDateString();

        $purchases = Purchase::where('subscription_id', $subscription->id)
            ->withCount([
                'deliveries as deliveries_count' => fn ($q) => $q->where('status', '!=', 'cancelled'),
                'deliveries as delivered_count' => fn ($q) => $q->where('status', 'delivered'),
            ])
            ->orderByDesc('id')
            ->get();

        $orders = PlannedOrder::where('subscription_id', $subscription->id)
            ->with(['animal', 'items.recipe']);

        $upcomingOrders = (clone $orders)
            ->whereDate('scheduled_for', '>=', $today)
            ->orderBy('scheduled_for')
            ->orderBy('id')
            ->limit(20)
            ->get();

        $pastOrders = (clone $orders)
            ->whereDate('scheduled_for', '<', $today)
            ->orderByDesc('scheduled_for')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $orderCounts = PlannedOrder::where('subscription_id', $subscription->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();



        return view('subscriptions.show', [
            'subscription' => $subscription,
            'account' => $subscription->customerAccount,
            'purchases' => $purchases,
            'upcomingOrders' => $upcomingOrders,
            'pastOrders' => $pastOrders,
            'orderCounts' => $orderCounts,
            'canUpdate' => $user->hasPermission('subscriptions.update'),
        ]);
    }
}
