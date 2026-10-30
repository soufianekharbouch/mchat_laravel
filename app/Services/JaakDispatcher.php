<?php

namespace App\Services;

use App\Models\CustomerAddress;
use App\Models\JaakDelivery;
use App\Models\PlannedOrder;
use App\Models\PurchaseDelivery;
use App\Support\DeliveryGroup;
use App\Support\KuwaitPhone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Daily report orders <-> JAAK.
 *
 * ONE JAAK delivery per customer + day + slot + address: the orders of
 * several pets in that delivery are sent as ONE task.
 *
 *  1. send()            order marked "Prepared" -> when all the pets of the
 *                       delivery are prepared, ONE JAAK task (status PAUSED)
 *  2. requestDrivers()  "Request drivers" button -> quote + accept
 *  3. sync()            every 10 minutes (JAAK has no webhook):
 *                       PICKUP/DROPOFF -> orders "shipped", DELIVERED -> "delivered"
 */
class JaakDispatcher
{
    private const READY = ['prepared', 'shipped', 'delivered'];

    public function __construct(private JaakClient $client)
    {
    }

    /*
    |--------------------------------------------------------------------------
    | 1. SEND THE DELIVERY OF AN ORDER
    |--------------------------------------------------------------------------
    |
    | $force = true: sent with the pets already prepared, without waiting
    | for the others (button "Send now").
    |
    */

    public function send(PlannedOrder $order, bool $force = false): JaakDelivery
    {
        // Same lock for all the pets of the customer that day
        $lock = Cache::lock('jaak-group-' . $order->subscription_id . '-' . $order->scheduled_for?->toDateString(), 60);

        if (!$lock->block(15)) {
            return JaakDelivery::forOrder($order->id)
                ?? new JaakDelivery(['status' => 'queued', 'last_error' => 'Busy, try again in a few seconds.']);
        }

        try {
            return $this->sendLocked($order->fresh(), $force);
        } finally {
            $lock->release();
        }
    }

    private function sendLocked(PlannedOrder $order, bool $force): JaakDelivery
    {
        // Orders before the JAAK start date (JAAK_START_DATE) are never sent
        if (!self::isAfterStartDate($order)) {
            return JaakDelivery::forOrder($order->id) ?? new JaakDelivery([
                'status' => 'queued',
                'last_error' => 'Order before the JAAK start date (' . config('services.jaak.start_date') . '): not sent.',
            ]);
        }

        $siblings = DeliveryGroup::siblings($order);
        $groupKey = (string) ($siblings->first()?->delivery_key ?? md5('po-' . $order->id));
        $siblingIds = $siblings->pluck('id')->all();

        // Deliveries already holding orders of this group
        $deliveries = JaakDelivery::whereHas('plannedOrders', fn ($q) => $q->whereIn('planned_orders.id', $siblingIds))
            ->with('plannedOrders:id')
            ->orderBy('id')
            ->get();

        $mine = $deliveries->first(fn ($d) => $d->plannedOrders->contains('id', $order->id));

        // Delivery at JAAK, still waiting for its driver: the pet is added to it
        $open = $deliveries->first(fn ($d) => $d->isOnJaak() && in_array($d->status, ['PAUSED', 'PENDING'], true));

        if ($mine && $mine->isOnJaak() && $mine->status !== 'CANCELLED') {
            return $mine;
        }

        if ((!$mine || !$mine->isOnJaak()) && $open && in_array($order->status, self::READY, true)) {
            $this->moveTo($open, [$order->id]);
            $open->log("{$this->petName($order)} added to this delivery (same customer, day and slot).");
            $open->save();

            return $open;
        }

        if ($mine && $mine->status === 'CANCELLED' && !$force) {
            return $mine;
        }

        // Orders carried by a delivery already at JAAK stay with it
        $taken = $deliveries
            ->filter(fn ($d) => $d->isOnJaak() && $d->status !== 'CANCELLED' && $d->id !== $mine?->id)
            ->flatMap(fn ($d) => $d->plannedOrders->pluck('id'))
            ->all();

        $free = $siblings->reject(fn ($o) => in_array($o->id, $taken, true));
        $ready = $free->filter(fn ($o) => in_array($o->status, self::READY, true))->values();
        $notReady = $free->reject(fn ($o) => in_array($o->status, self::READY, true))->values();

        // Delivery not at JAAK yet (or cancelled, sent again) for these orders
        $delivery = $mine
            ?? $deliveries->first(fn ($d) => !$d->isOnJaak())
            ?? $this->newDelivery($order, $groupKey);

        if ($delivery->isOnJaak() && $delivery->status === 'CANCELLED') {
            $delivery->external_id = JaakDelivery::externalIdFor($delivery->id) . '-R' . ((int) $delivery->attempts + 1);
            $delivery->jaak_task_id = null;
            $delivery->jaak_batch_id = null;
            $delivery->drivers_requested_at = null;
            $delivery->min_fee = null;
            $delivery->max_fee = null;
        }

        $this->attach($delivery, $ready->pluck('id')->all());

        $delivery->fill([
            'planned_order_id' => $ready->first()?->id ?? $order->id,
            'group_key' => $groupKey,
            'subscription_id' => $order->subscription_id,
            'delivery_date' => $order->scheduled_for,
            'delivery_slot' => $siblings->first()?->delivery_slot_resolved ?: null,
        ]);

        if ($ready->isEmpty()) {
            $delivery->status = 'queued';
            $delivery->last_error = 'No prepared order in this delivery.';
            $delivery->save();

            return $delivery;
        }

        // Other pets of the same delivery not prepared yet: wait for them
        if ($notReady->isNotEmpty() && !$force) {
            if ($delivery->status !== 'waiting') {
                $delivery->log('Waiting for ' . $notReady->map(fn ($o) => $this->petName($o))->implode(', ')
                    . ' to be prepared (same delivery).');
            }

            $delivery->status = 'waiting';
            $delivery->last_error = null;
            $delivery->save();

            return $delivery;
        }

        return $this->createTask($delivery, $ready);
    }

    /** JAAK used only for the deliveries from JAAK_START_DATE (empty = always). */
    public static function isAfterStartDate(PlannedOrder $order): bool
    {
        $start = config('services.jaak.start_date');

        if (blank($start) || !$order->scheduled_for) {
            return true;
        }

        return $order->scheduled_for->toDateString() >= \Carbon\Carbon::parse($start)->toDateString();
    }

    /** Called when an order is marked "Prepared": never blocks the daily report. */
    public function sendSafely(PlannedOrder $order): void
    {
        if (!config('services.jaak.send_on_prepared', true)) {
            return;
        }

        try {
            $this->send($order);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param Collection<int, PlannedOrder> $orders
     */
    private function createTask(JaakDelivery $delivery, Collection $orders): JaakDelivery
    {
        if (!JaakClient::enabled()) {
            $delivery->status = 'queued';
            $delivery->last_error = 'JAAK disabled (JAAK_ENABLED / JAAK_API_KEY in .env).';
            $delivery->save();

            return $delivery;
        }

        $main = $orders->first();
        $recipient = $this->recipient($main);

        $delivery->fill([
            'recipient_name' => $recipient['name'],
            'recipient_phone' => $recipient['phone'],
            'address_text' => $recipient['address'],
            'zone_name' => $recipient['zone'],
        ]);

        $missing = array_keys(array_filter([
            'phone' => !$recipient['phone'],
            'address' => !$recipient['address'],
        ]));

        if ($missing) {
            $delivery->status = 'failed';
            $delivery->last_error = 'Not sent: ' . implode(' and ', $missing) . ' missing for this customer.';
            $delivery->log($delivery->last_error);
            $delivery->save();

            return $delivery;
        }

        $payload = $this->taskPayload($main, $orders, $delivery, $recipient);
        $delivery->request_payload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $delivery->attempts = (int) $delivery->attempts + 1;

        // Previous attempt cut (timeout): maybe already created at JAAK
        if ($delivery->attempts > 1 && $this->adoptExisting($delivery)) {
            $delivery->save();

            return $delivery;
        }

        try {
            $created = $this->client->createTasks([$payload]);
            $taskId = (string) ($created[0]['id'] ?? '');

            if ($taskId === '') {
                throw new \RuntimeException('JAAK did not return a task id.');
            }

            $delivery->fill([
                'jaak_task_id' => $taskId,
                'status' => 'PAUSED',
                'sent_at' => now(),
                'last_error' => null,
            ]);
            $delivery->log("Task created at JAAK (#{$taskId}) for " . $orders->count() . ' order(s): '
                . $orders->map(fn ($o) => $this->petName($o))->implode(', ') . '. Waiting for "Request drivers".');
        } catch (Throwable $exception) {
            // 4xx = refused (data), otherwise retried by the 10-minute task
            $refused = str_contains($exception->getMessage(), 'HTTP 4');

            $delivery->status = $refused ? 'failed' : 'queued';
            $delivery->last_error = $exception->getMessage();
            $delivery->log('Send failed: ' . $exception->getMessage());

            Log::warning('JAAK send failed', ['jaak_delivery_id' => $delivery->id, 'error' => $exception->getMessage()]);
        }

        $delivery->save();

        if ($delivery->status === 'PAUSED' && config('services.jaak.auto_request_drivers', false)) {
            $this->requestDrivers(collect([$delivery]));
        }

        return $delivery->fresh();
    }

    /*
    |--------------------------------------------------------------------------
    | 2. REQUEST DRIVERS (quote + accept)
    |--------------------------------------------------------------------------
    */

    /**
     * @param Collection<int, JaakDelivery> $deliveries
     * @return array{requested: int, batches: int, fee_min: float, fee_max: float, error: ?string}
     */
    public function requestDrivers(Collection $deliveries): array
    {
        $result = ['requested' => 0, 'batches' => 0, 'fee_min' => 0.0, 'fee_max' => 0.0, 'error' => null];

        $waiting = $deliveries
            ->filter(fn (JaakDelivery $d) => $d->isOnJaak() && $d->status === 'PAUSED')
            ->keyBy(fn (JaakDelivery $d) => (string) $d->jaak_task_id);

        if ($waiting->isEmpty()) {
            $result['error'] = 'No delivery waiting for a driver.';

            return $result;
        }

        try {
            $batches = $this->client->quotes($waiting->keys()->all());

            $batchIds = [];

            foreach ($batches as $batch) {
                $batchId = (string) ($batch['batch_id'] ?? $batch['id'] ?? '');

                if ($batchId === '') {
                    continue;
                }

                $batchIds[] = $batchId;
                $result['fee_min'] += (float) ($batch['min_fee'] ?? 0);
                $result['fee_max'] += (float) ($batch['max_fee'] ?? 0);

                $taskIds = collect($batch['tasks'] ?? [])->pluck('id')->map(fn ($id) => (string) $id);
                $count = max(1, $taskIds->count());

                foreach ($taskIds as $taskId) {
                    $delivery = $waiting->get($taskId);

                    if (!$delivery) {
                        continue;
                    }

                    $delivery->fill([
                        'jaak_batch_id' => $batchId,
                        // Fee of the batch shared between its tasks
                        'min_fee' => isset($batch['min_fee']) ? round($batch['min_fee'] / $count, 3) : null,
                        'max_fee' => isset($batch['max_fee']) ? round($batch['max_fee'] / $count, 3) : null,
                        'currency' => $batch['currency_code'] ?? 'KWD',
                    ]);
                }
            }

            if (!$batchIds) {
                throw new \RuntimeException('JAAK returned no batch.');
            }

            $this->client->acceptBatches($batchIds);

            foreach ($waiting as $delivery) {
                if (!$delivery->jaak_batch_id) {
                    continue;
                }

                $delivery->fill([
                    'status' => 'PENDING',
                    'drivers_requested_at' => now(),
                    'last_error' => null,
                ]);
                $delivery->log("Driver requested (batch #{$delivery->jaak_batch_id}"
                    . ($delivery->max_fee !== null ? ", about {$delivery->min_fee}-{$delivery->max_fee} {$delivery->currency}" : '') . ').');
                $delivery->save();

                $result['requested']++;
            }

            $result['batches'] = count($batchIds);
        } catch (Throwable $exception) {
            $result['error'] = $exception->getMessage();

            foreach ($waiting as $delivery) {
                $delivery->last_error = $exception->getMessage();
                $delivery->log('Request drivers failed: ' . $exception->getMessage());
                $delivery->save();
            }

            Log::warning('JAAK request drivers failed', ['error' => $exception->getMessage()]);
        }

        return $result;
    }

    /*
    |--------------------------------------------------------------------------
    | 3. STATUS SYNC (no webhook at JAAK)
    |--------------------------------------------------------------------------
    */

    /**
     * @return array{checked: int, changed: int, error: ?string}
     */
    public function sync(?Collection $deliveries = null): array
    {
        $deliveries ??= JaakDelivery::query()
            ->whereNotNull('jaak_task_id')
            ->whereIn('status', JaakDelivery::OPEN_STATUSES)
            ->get();

        $result = ['checked' => $deliveries->count(), 'changed' => 0, 'error' => null];

        if ($deliveries->isEmpty() || !JaakClient::enabled()) {
            return $result;
        }

        try {
            $tasks = collect($this->client->tasksByExternalIds($deliveries->pluck('external_id')->all()));
        } catch (Throwable $exception) {
            $result['error'] = $exception->getMessage();
            Log::warning('JAAK sync failed', ['error' => $exception->getMessage()]);

            return $result;
        }

        foreach ($deliveries as $delivery) {
            $task = $tasks->first(fn ($t) => (string) ($t['id'] ?? '') === (string) $delivery->jaak_task_id)
                ?? $tasks->first(fn ($t) => ($t['external_id'] ?? null) === $delivery->external_id);

            $delivery->last_synced_at = now();

            if ($task && !empty($task['status']) && $task['status'] !== $delivery->status) {
                $old = $delivery->status;
                $delivery->status = (string) $task['status'];
                $delivery->log("JAAK status: {$old} -> {$delivery->status}");
                $this->applyStatus($delivery);
                $result['changed']++;
            }

            $delivery->save();
        }

        return $result;
    }

    /**
     * Every 10 minutes: sends again what failed (network) and re-checks the
     * deliveries waiting for other pets (a pet moved to another day...).
     */
    public function retryQueued(): int
    {
        $count = 0;

        JaakDelivery::query()
            ->whereNull('jaak_task_id')
            ->where(fn ($q) => $q->where('status', 'waiting')
                ->orWhere(fn ($q) => $q->where('status', 'queued')->where('attempts', '<', 6)))
            ->where('updated_at', '<', now()->subMinutes(9))
            ->with('plannedOrders')
            ->get()
            ->each(function (JaakDelivery $delivery) use (&$count) {
                $order = $delivery->plannedOrders->first(fn ($o) => in_array($o->status, self::READY, true));

                if ($order) {
                    $this->send($order);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * JAAK status -> all the orders of the delivery (never goes back).
     */
    private function applyStatus(JaakDelivery $delivery): void
    {
        $orders = $delivery->plannedOrders()->get();

        foreach ($orders as $order) {
            if (in_array($delivery->status, ['PICKUP', 'DROPOFF'], true)
                && in_array($order->status, ['planned', 'overdue', 'prepared'], true)) {
                $order->status = 'shipped';
                $order->shipped_at ??= now();
                $order->save();
            }

            if ($delivery->status === 'DELIVERED' && $order->status !== 'delivered') {
                $order->status = 'delivered';
                $order->shipped_at ??= now();
                $order->delivered_at ??= now();
                $order->save();
            }
        }

        if (in_array($delivery->status, ['PICKUP', 'DROPOFF'], true)) {
            $delivery->picked_up_at ??= now();
        }

        if ($delivery->status === 'DELIVERED') {
            $delivery->delivered_at ??= now();

            // Pack deliveries (app) follow the daily report
            PurchaseDelivery::whereIn('planned_order_id', $orders->pluck('id'))
                ->where('status', '!=', 'cancelled')
                ->update(['status' => 'delivered']);
        }

        // Customer notification (app): driver has the parcel / delivered
        if ($delivery->status === 'DROPOFF') {
            app(OrderStatusNotifier::class)->notify($orders, 'on_the_way');
        }

        if ($delivery->status === 'DELIVERED') {
            app(OrderStatusNotifier::class)->notify($orders, 'delivered');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    private function newDelivery(PlannedOrder $order, string $groupKey): JaakDelivery
    {
        $delivery = JaakDelivery::create([
            'planned_order_id' => $order->id,
            'group_key' => $groupKey ?: null,
            'subscription_id' => $order->subscription_id,
            'delivery_date' => $order->scheduled_for,
            'external_id' => 'tmp-' . Str::uuid(),
            'status' => 'queued',
        ]);

        $delivery->external_id = JaakDelivery::externalIdFor($delivery->id);
        $delivery->save();

        return $delivery;
    }

    /** Adds orders to a delivery, keeping the ones it already has. */
    private function moveTo(JaakDelivery $delivery, array $orderIds): void
    {
        $this->attach($delivery, $orderIds, false);
    }

    /** Orders carried by a delivery (moved from another one not sent if needed). */
    private function attach(JaakDelivery $delivery, array $orderIds, bool $exact = true): void
    {
        DB::transaction(function () use ($delivery, $orderIds, $exact) {
            $others = DB::table('jaak_delivery_orders')
                ->whereIn('planned_order_id', $orderIds)
                ->where('jaak_delivery_id', '!=', $delivery->id)
                ->pluck('jaak_delivery_id')
                ->unique();

            DB::table('jaak_delivery_orders')
                ->whereIn('planned_order_id', $orderIds)
                ->where('jaak_delivery_id', '!=', $delivery->id)
                ->delete();

            $exact
                ? $delivery->plannedOrders()->sync($orderIds)
                : $delivery->plannedOrders()->syncWithoutDetaching($orderIds);

            // Empty deliveries never sent: removed
            JaakDelivery::whereIn('id', $others)
                ->whereNull('jaak_task_id')
                ->whereDoesntHave('plannedOrders')
                ->delete();
        });
    }

    private function petName(PlannedOrder $order): string
    {
        $order->loadMissing('animal');

        return $order->animal?->name ?: 'order #' . $order->id;
    }

    /**
     * Customer, phone and address of the order:
     * pack delivery address (app) > default address > subscription address.
     *
     * @return array{name: string, phone: ?string, address: ?string, zone: ?string, province: ?string, note: ?string, slot: ?string, email: ?string}
     */
    public function recipient(PlannedOrder $order): array
    {
        $order->loadMissing(['subscription.customerAccount', 'animal']);
        $subscription = $order->subscription;
        $account = $subscription?->customerAccount;

        $packDelivery = PurchaseDelivery::where('planned_order_id', $order->id)
            ->where('status', '!=', 'cancelled')
            ->with('customerAddress.zone.province')
            ->first();

        $address = $packDelivery?->customerAddress;

        if (!$address && $account) {
            $address = CustomerAddress::with('zone.province')
                ->where('customer_account_id', $account->id)
                ->orderByDesc('is_default')
                ->orderByDesc('id')
                ->first();
        }

        $name = trim(($address?->first_name ?: $subscription?->subscriber_first_name) . ' '
            . ($address?->last_name ?: $subscription?->subscriber_last_name));

        $phone = collect([$address?->phone, $account?->phone, $subscription?->subscriber_phone])
            ->map(fn ($value) => KuwaitPhone::international($value))
            ->filter()
            ->first();

        $text = trim((string) ($address?->address ?: $subscription?->subscriber_address));

        return [
            'name' => $name !== '' ? $name : 'Customer',
            'phone' => $phone ? '+' . $phone : null,
            'address' => $text !== '' ? $text : null,
            'zone' => $address?->zone?->name ?: $address?->city ?: $subscription?->subscriber_zone,
            'province' => $address?->zone?->province?->name ?: $address?->province ?: $subscription?->subscriber_province,
            'note' => $address?->note ?: $subscription?->subscriber_note,
            'slot' => $packDelivery?->delivery_slot ?: $address?->delivery_slot ?: $subscription?->subscriber_delivery_slot,
            'email' => $account?->email,
        ];
    }

    /**
     * One JAAK task for all the pets of the delivery.
     *
     * @param Collection<int, PlannedOrder> $orders
     */
    private function taskPayload(PlannedOrder $main, Collection $orders, JaakDelivery $delivery, array $recipient): array
    {
        $subscription = $main->subscription;
        $pets = $orders->map(fn ($o) => $this->petName($o))->implode(' + ');

        $payload = [
            'destination' => [
                'kind' => 'UNSTRUCTURED',
                'country' => 'Kuwait',
                'city' => (string) ($recipient['zone'] ?: $recipient['province'] ?: 'Kuwait'),
                'address_line_1' => mb_substr($recipient['address'], 0, 250),
            ],
            'phone' => $recipient['phone'],
            'name' => $recipient['name'],
            'external_id' => $delivery->external_id,
            // Shown at JAAK: delivery number + subscription code + pets (number of bags)
            'external_ref' => mb_substr(trim(DeliveryGroup::numberFor($main) . ' · ' . ($subscription?->code ?? ('D-' . $delivery->id))
                . " · {$pets}" . ($orders->count() > 1 ? ' (' . $orders->count() . ' bags)' : '')), 0, 190),
        ];

        if (filled($recipient['email'])) {
            $payload['email'] = $recipient['email'];
        }

        try {
            $payload['external_url'] = route('orders.show', $main);
        } catch (Throwable) {
            // route not loaded (console): optional field
        }

        return $payload;
    }

    /** Task already created by a previous (cut) attempt: taken back. */
    private function adoptExisting(JaakDelivery $delivery): bool
    {
        try {
            $task = collect($this->client->tasksByExternalIds([$delivery->external_id]))->first();
        } catch (Throwable) {
            return false;
        }

        if (!$task || empty($task['id'])) {
            return false;
        }

        $delivery->fill([
            'jaak_task_id' => (string) $task['id'],
            'status' => (string) ($task['status'] ?? 'PAUSED'),
            'sent_at' => $delivery->sent_at ?? now(),
            'last_error' => null,
        ]);
        $delivery->log("Task found at JAAK (#{$task['id']}) after an interrupted attempt.");

        return true;
    }
}
