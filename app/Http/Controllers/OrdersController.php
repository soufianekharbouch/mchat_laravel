<?php

namespace App\Http\Controllers;

use App\Models\JaakDelivery;
use App\Models\PlannedOrder;
use App\Services\JaakClient;
use App\Services\JaakDispatcher;
use App\Support\DeliveryGroup;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

/**
 * "Orders" menu: history of the deliveries with their preparation status
 * and their JAAK status. Today by default.
 *
 * One line = one delivery = same customer + day + slot + address: the
 * pets of the same delivery count as ONE order (one JAAK task).
 */
class OrdersController extends Controller
{
    public const ORDER_STATUSES = [
        'planned' => ['label' => 'Planned', 'color' => 'secondary', 'icon' => 'fa-calendar'],
        'overdue' => ['label' => 'Overdue', 'color' => 'danger', 'icon' => 'fa-exclamation-circle'],
        'prepared' => ['label' => 'Prepared', 'color' => 'info', 'icon' => 'fa-box'],
        'shipped' => ['label' => 'Shipped', 'color' => 'warning', 'icon' => 'fa-shipping-fast'],
        'delivered' => ['label' => 'Delivered', 'color' => 'success', 'icon' => 'fa-check-circle'],
    ];

    public function __construct(private JaakDispatcher $dispatcher)
    {
    }

    public function index(Request $request)
    {
        $this->authorizePermission('orders.view');

        $today = Carbon::today()->toDateString();

        $filters = [
            // Empty from/to (link "All history") = no date limit
            'from' => (string) ($request->has('from') ? $request->input('from') : $today),
            'to' => (string) ($request->has('to') ? $request->input('to') : ($request->has('from') ? $request->input('from') : $today)),
            'status' => (string) $request->input('status', ''),
            'jaak' => (string) $request->input('jaak', ''),
            // Delivery slot: 09:00-12:00 / 12:00-15:00 / none
            'slot' => (string) $request->input('slot', ''),
            'q' => trim((string) $request->input('q', '')),
        ];

        $from = $filters['from'] !== '' ? Carbon::parse($filters['from'])->toDateString() : null;
        $to = $filters['to'] !== '' ? Carbon::parse($filters['to'])->toDateString() : null;

        if ($from && $to && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        $key = DeliveryGroup::keySql();
        $rank = DeliveryGroup::rankSql();

        // Orders of the period (+ search), with the delivery joins
        $base = DeliveryGroup::join(PlannedOrder::query())
            ->when($from, fn ($query) => $query->whereDate('planned_orders.scheduled_for', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('planned_orders.scheduled_for', '<=', $to))
            ->when($filters['q'] !== '', function ($query) use ($filters) {
                $search = '%' . $filters['q'] . '%';
                $digits = preg_replace('/\D+/', '', $filters['q']);

                // Delivery number DL-261021-1234: the customer's deliveries that day
                if ($number = DeliveryGroup::parseNumber($filters['q'])) {
                    $query->where('planned_orders.subscription_id', $number['subscription_id'])
                        ->whereDate('planned_orders.scheduled_for', $number['date']);

                    return;
                }

                $query->where(function ($query) use ($search, $digits, $filters) {
                    // JAAK reference (MC-D-45) or JAAK task id
                    $query->orWhereIn('planned_orders.id', DB::table('jaak_delivery_orders')
                        ->join('jaak_deliveries', 'jaak_deliveries.id', '=', 'jaak_delivery_orders.jaak_delivery_id')
                        ->where(fn ($q) => $q->where('jaak_deliveries.external_id', $filters['q'])
                            ->orWhere('jaak_deliveries.jaak_task_id', ltrim($filters['q'], '#')))
                        ->select('jaak_delivery_orders.planned_order_id'));

                    if (ctype_digit($filters['q'])) {
                        $query->orWhere('planned_orders.id', (int) $filters['q']);
                    }

                    $query->orWhere('dg_s.code', 'like', $search)
                        ->orWhere('dg_s.subscriber_first_name', 'like', $search)
                        ->orWhere('dg_s.subscriber_last_name', 'like', $search)
                        ->orWhereHas('animal', fn ($animal) => $animal->where('name', 'like', $search));

                    if (strlen($digits) >= 4) {
                        $query->orWhere('dg_s.subscriber_phone', 'like', '%' . $digits . '%');
                    }
                });
            });

        // Slot filter (same slot as the delivery grouping)
        if ($filters['slot'] !== '') {
            $filters['slot'] === 'none'
                ? $base->whereRaw(DeliveryGroup::slotSql() . " = ''")
                : $base->whereRaw(DeliveryGroup::slotSql() . ' = ?', [$filters['slot']]);
        }

        // Deliveries by slot (for the slot chips, without the slot filter)
        $slotCounts = DB::query()
            ->fromSub(
                DeliveryGroup::join(PlannedOrder::query())
                    ->when($from, fn ($query) => $query->whereDate('planned_orders.scheduled_for', '>=', $from))
                    ->when($to, fn ($query) => $query->whereDate('planned_orders.scheduled_for', '<=', $to))
                    ->toBase()
                    ->selectRaw("{$key} AS dk, MIN(" . DeliveryGroup::slotSql() . ') AS sl')
                    ->groupBy('dk'),
                'groups_by_slot'
            )
            ->selectRaw('sl, COUNT(*) AS total')
            ->groupBy('sl')
            ->pluck('total', 'sl')
            ->all();

        $baseIds = (clone $base)->select('planned_orders.id');

        // Counters by delivery (lowest status of its pets)
        $rankCounts = DB::query()
            ->fromSub((clone $base)->toBase()->selectRaw("{$key} AS dk, MIN({$rank}) AS rk")->groupBy('dk'), 'groups_by_rank')
            ->selectRaw('rk, COUNT(*) AS total')
            ->groupBy('rk')
            ->pluck('total', 'rk');

        $statusCounts = [];
        foreach (DeliveryGroup::RANKS as $index => $status) {
            $statusCounts[$status] = (int) ($rankCounts[$index] ?? 0);
        }

        $jaakCounts = JaakDelivery::query()
            ->whereHas('plannedOrders', fn ($q) => $q->whereIn('planned_orders.id', $baseIds))
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        // One line per delivery
        $rankFilter = array_search($filters['status'], DeliveryGroup::RANKS, true);

        $groups = (clone $base)->toBase()
            ->selectRaw("{$key} AS delivery_key, MIN(planned_orders.scheduled_for) AS delivery_date, MIN({$rank}) AS status_rank, COUNT(*) AS orders_count")
            ->when($filters['jaak'] !== '', function ($query) use ($filters) {
                $linked = DB::table('jaak_delivery_orders')
                    ->join('jaak_deliveries', 'jaak_deliveries.id', '=', 'jaak_delivery_orders.jaak_delivery_id')
                    ->when($filters['jaak'] !== 'none', fn ($q) => $q->where('jaak_deliveries.status', $filters['jaak']))
                    ->select('jaak_delivery_orders.planned_order_id');

                $filters['jaak'] === 'none'
                    ? $query->whereNotIn('planned_orders.id', $linked)
                    : $query->whereIn('planned_orders.id', $linked);
            })
            ->groupBy('delivery_key')
            ->when($rankFilter !== false, fn ($query) => $query->havingRaw("MIN({$rank}) = ?", [$rankFilter]))
            ->orderBy('delivery_date', $from && $from === $to ? 'asc' : 'desc')
            ->orderBy('delivery_key')
            ->paginate(50)
            ->withQueryString();

        // Orders (pets) of the deliveries of this page
        $keys = collect($groups->items())->pluck('delivery_key')->all();

        $ordersByGroup = $keys
            ? DeliveryGroup::ordersQuery()
                ->when($from, fn ($query) => $query->whereDate('planned_orders.scheduled_for', '>=', $from))
                ->when($to, fn ($query) => $query->whereDate('planned_orders.scheduled_for', '<=', $to))
                ->whereRaw("{$key} IN (" . implode(',', array_fill(0, count($keys), '?')) . ')', $keys)
                ->with(['subscription', 'animal', 'items.recipe'])
                ->orderBy('planned_orders.id')
                ->get()
                ->groupBy('delivery_key')
            : collect();

        $orderIds = $ordersByGroup->flatten(1)->pluck('id');

        // Delivery numbers (DL-261021-1234) of this page
        $numbers = DeliveryGroup::numbers($ordersByGroup->map->first()->values());

        $deliveriesByOrder = [];
        JaakDelivery::whereHas('plannedOrders', fn ($q) => $q->whereIn('planned_orders.id', $orderIds))
            ->with('plannedOrders:id')
            ->get()
            ->each(function (JaakDelivery $delivery) use (&$deliveriesByOrder) {
                foreach ($delivery->plannedOrders as $order) {
                    $deliveriesByOrder[$order->id] = $delivery;
                }
            });

        // Header: waiting for "Request drivers" / for other pets (all dates)
        $waitingCount = JaakDelivery::where('status', 'PAUSED')->whereNotNull('jaak_task_id')->count();
        $waitingPetsCount = JaakDelivery::where('status', 'waiting')->count();

        // Last automatic / manual reading of the JAAK statuses
        $lastSyncAt = JaakDelivery::max('last_synced_at');
        $openAtJaak = JaakDelivery::whereNotNull('jaak_task_id')->whereIn('status', JaakDelivery::OPEN_STATUSES)->count();

        return view('orders.index', [
            'groups' => $groups,
            'ordersByGroup' => $ordersByGroup,
            'numbers' => $numbers,
            'deliveriesByOrder' => $deliveriesByOrder,
            'filters' => $filters,
            'statusCounts' => $statusCounts,
            'jaakCounts' => $jaakCounts,
            'slotCounts' => $slotCounts,
            'slots' => \App\Models\CustomerAddress::DELIVERY_SLOTS,
            'waitingCount' => $waitingCount,
            'waitingPetsCount' => $waitingPetsCount,
            'lastSyncAt' => $lastSyncAt ? Carbon::parse($lastSyncAt) : null,
            'openAtJaak' => $openAtJaak,
            'jaakEnabled' => JaakClient::enabled(),
            'isToday' => $from === $today && $to === $today,
            'canSend' => $request->user()->hasPermission('orders.send_jaak'),
            'canRequestDrivers' => $request->user()->hasPermission('orders.request_drivers'),
        ]);
    }

    /** One delivery: all the pets delivered with this order. */
    public function show(Request $request, PlannedOrder $plannedOrder)
    {
        $this->authorizePermission('orders.view');

        $orders = DeliveryGroup::siblings($plannedOrder)
            ->load(['subscription', 'animal', 'items.recipe']);

        $deliveries = JaakDelivery::whereHas('plannedOrders', fn ($q) => $q->whereIn('planned_orders.id', $orders->pluck('id')))
            ->with('plannedOrders:id')
            ->orderBy('id')
            ->get();

        return view('orders.show', [
            'order' => $orders->firstWhere('id', $plannedOrder->id) ?? $plannedOrder,
            'orders' => $orders,
            'number' => DeliveryGroup::numberFor($plannedOrder),
            'deliveries' => $deliveries,
            'recipient' => $this->dispatcher->recipient($plannedOrder),
            'jaakEnabled' => JaakClient::enabled(),
            'canSend' => $request->user()->hasPermission('orders.send_jaak'),
            'canRequestDrivers' => $request->user()->hasPermission('orders.request_drivers'),
        ]);
    }

    /** "Request drivers": every delivery waiting at JAAK, quoted and accepted together. */
    public function requestDrivers(Request $request)
    {
        $this->authorizePermission('orders.request_drivers');

        $waiting = JaakDelivery::where('status', 'PAUSED')
            ->whereNotNull('jaak_task_id')
            ->when($request->filled('ids'), fn ($query) => $query->whereIn('id', (array) $request->input('ids')))
            ->get();

        $result = $this->dispatcher->requestDrivers($waiting);

        if ($result['error'] && $result['requested'] === 0) {
            return back()->with('error', 'JAAK: ' . $result['error']);
        }

        $message = "{$result['requested']} delivery(ies) sent to JAAK drivers in {$result['batches']} route(s)";

        if ($result['fee_max'] > 0) {
            $message .= sprintf(' - estimated fee %.3f to %.3f KWD', $result['fee_min'], $result['fee_max']);
        }

        $redirect = back()->with('success', $message . '.');

        return $result['error'] ? $redirect->with('error', 'Some deliveries failed: ' . $result['error']) : $redirect;
    }

    /**
     * Send / retry the delivery of an order at JAAK.
     * force=1: "Send now" without waiting for the other pets.
     */
    public function send(Request $request, PlannedOrder $plannedOrder)
    {
        $this->authorizePermission('orders.send_jaak');

        if (!JaakClient::enabled()) {
            return back()->with('error', 'JAAK is disabled (JAAK_ENABLED / JAAK_API_KEY in .env).');
        }

        if (!in_array($plannedOrder->status, ['prepared', 'shipped'], true)) {
            return back()->with('error', 'Only a "Prepared" order can be sent to JAAK.');
        }

        $delivery = $this->dispatcher->send($plannedOrder, $request->boolean('force'));

        if ($delivery->status === 'waiting') {
            return back()->with('error', 'Waiting for the other pets of this delivery to be prepared. Use "Send now" to send without them.');
        }

        return $delivery->isOnJaak() && $delivery->status !== 'CANCELLED'
            ? back()->with('success', "Delivery {$delivery->external_id} is at JAAK ({$delivery->statusConfig()['label']}).")
            : back()->with('error', 'Not sent: ' . ($delivery->last_error ?: 'unknown error.'));
    }

    /** Reads the JAAK statuses now (instead of waiting for the 10-minute task). */
    public function sync(Request $request)
    {
        $this->authorizePermission('orders.view');

        $deliveries = $request->filled('delivery_ids')
            ? JaakDelivery::whereIn('id', (array) $request->input('delivery_ids'))->whereNotNull('jaak_task_id')->get()
            : null;

        $result = $this->dispatcher->sync($deliveries);

        return $result['error']
            ? back()->with('error', 'JAAK: ' . $result['error'])
            : back()->with('success', "Statuses updated: {$result['checked']} checked, {$result['changed']} changed.");
    }

    /** Tests the API key (GET /me). */
    public function check(JaakClient $client)
    {
        $this->authorizePermission('orders.send_jaak');

        if (!JaakClient::enabled()) {
            return back()->with('error', 'JAAK is disabled (JAAK_ENABLED / JAAK_API_KEY in .env).');
        }

        try {
            $me = $client->me();

            return back()->with('success', 'JAAK connection OK (organization ' . ($me['org_id'] ?? '?') . ').');
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    private function authorizePermission(string $permission): void
    {
        $user = auth()->user();

        abort_unless($user && $user->hasPermission($permission), 403);
    }
}
