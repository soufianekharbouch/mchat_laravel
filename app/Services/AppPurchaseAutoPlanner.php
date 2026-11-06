<?php

namespace App\Services;

use App\Models\Purchase;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * App purchases: deliveries planned AUTOMATICALLY as soon as the pack is paid
 * (the customer has nothing to do, he can still change everything later).
 *
 *  - first delivery : the day after the order (order 02/11 23:00 -> 03/11,
 *                     order 03/11 00:30 -> 04/11), never on Sunday
 *  - several packs of the same pet: the next one starts when the meals of
 *    the previous one are eaten
 *  - pet renewing: same distribution as its previous similar pack (same
 *    deliveries, same recipes), otherwise the default one (max deliveries,
 *    recipes shared)
 *  - address: the default address of the customer (none: not planned, the
 *    customer plans himself)
 *  - PREMIUM- packs waiting for our team: not planned (AutoDeliveryPlanner)
 */
class AppPurchaseAutoPlanner
{
    public function __construct(
        private AutoDeliveryPlanner $planner,
        private PurchaseScheduleService $schedule
    ) {
    }

    /**
     * @param iterable<Purchase> $purchases
     * @return array<int, array{ok: bool, message: string}> by purchase id
     */
    public function planPaid(iterable $purchases): array
    {
        $results = [];

        $list = collect($purchases)
            ->filter()
            ->map(fn (Purchase $purchase) => $purchase->fresh())
            ->filter()
            ->sortBy('id')
            ->values();

        foreach ($list as $purchase) {
            try {
                $results[$purchase->id] = $this->planOne($purchase);
            } catch (Throwable $exception) {
                report($exception);
                $results[$purchase->id] = ['ok' => false, 'message' => $exception->getMessage()];
            }
        }

        return $results;
    }

    private function planOne(Purchase $purchase): array
    {
        if ($purchase->status !== 'paid') {
            return ['ok' => false, 'message' => 'Not paid.'];
        }

        // Already planned (customer or staff): never replaced
        if ($purchase->deliveries()->where('status', '!=', 'cancelled')->exists()) {
            return ['ok' => true, 'message' => 'Already planned.'];
        }

        $customer = $purchase->customerAccount;
        $animal = $purchase->animal;

        if (!$customer || !$animal) {
            return ['ok' => false, 'message' => 'Customer or pet missing.'];
        }

        $addressId = $customer->addresses()->orderByDesc('is_default')->orderBy('id')->value('id');

        if (!$addressId) {
            return ['ok' => false, 'message' => 'No delivery address: the customer plans himself.'];
        }

        // Order time (not the time the bank confirmed): the day after
        $orderDate = Carbon::parse($purchase->purchased_at ?? now());

        $result = $this->planner->plan(
            $purchase,
            $customer,
            $animal,
            $orderDate,
            $addressId,
            'system',
            // Pack 2/3 of the pet: after the meals of the previous packs
            $this->schedule->startAfterPreviousPacks($purchase),
            null,
            $this->planner->templateFrom($purchase),
            true
        );

        if ($result['ok']) {
            $purchase->recordStatusHistory($purchase->fresh()->status, $purchase->fresh()->status, 'Deliveries planned automatically after the payment: ' . $result['message'], 'system');
        } else {
            Log::info('App purchase not planned automatically', ['purchase_id' => $purchase->id, 'reason' => $result['message']]);
        }

        return ['ok' => (bool) $result['ok'], 'message' => (string) $result['message']];
    }

    /** First delivery of a pack (Y-m-d), null when nothing planned. */
    public static function firstDelivery(Purchase $purchase): ?string
    {
        $date = $purchase->deliveries()->where('status', '!=', 'cancelled')->min('delivery_date');

        return $date ? Carbon::parse($date)->format('Y-m-d') : null;
    }
}
