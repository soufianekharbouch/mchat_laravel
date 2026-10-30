<?php

namespace App\Http\Controllers;

use App\Models\CustomerAccount;
use App\Models\JaakDelivery;
use App\Models\PlannedOrder;
use App\Support\DeliveryGroup;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Mobile app: status of the customer's deliveries.
 *
 *   POST /api/mobile/orders/tracking            upcoming + recent deliveries
 *   POST /api/mobile/orders/tracking/{number}   one delivery (DL-261021-1234)
 *
 * One delivery = same day + slot + address (several pets = one delivery).
 * Steps: scheduled -> prepared -> on_the_way -> delivered
 */
class MobileOrderTrackingController extends Controller
{
    private const STEPS = ['scheduled', 'prepared', 'on_the_way', 'delivered'];

    private const LABELS = [
        'en' => [
            'scheduled' => 'Scheduled',
            'prepared' => 'Prepared',
            'on_the_way' => 'On the way',
            'delivered' => 'Delivered',
        ],
        'ar' => [
            'scheduled' => 'مجدول',
            'prepared' => 'تم التحضير',
            'on_the_way' => 'في الطريق',
            'delivered' => 'تم التوصيل',
        ],
    ];

    public function index(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        if (!$customer) {
            return $this->unauthenticated($request);
        }

        $lang = $this->language($request, $customer);
        $today = Carbon::today();

        // Recent days + upcoming ones (?days_back=14&days_ahead=60)
        $from = $today->copy()->subDays(min(90, max(0, (int) $request->input('days_back', 14))));
        $to = $today->copy()->addDays(min(180, max(0, (int) $request->input('days_ahead', 60))));

        $deliveries = $this->deliveries($customer, $from, $to, $lang);

        return response()->json([
            'success' => true,
            'upcoming' => $deliveries->filter(fn ($d) => $d['step'] !== 'delivered' && $d['date'] >= $today->toDateString())->values(),
            'recent' => $deliveries->filter(fn ($d) => $d['step'] === 'delivered' || $d['date'] < $today->toDateString())
                ->sortByDesc('date')->values(),
            // Delivery of today in progress (banner in the app)
            'current' => $deliveries->first(fn ($d) => $d['is_today'] && $d['step'] !== 'delivered'),
        ]);
    }

    public function show(Request $request, string $number): JsonResponse
    {
        $customer = $this->customer($request);

        if (!$customer) {
            return $this->unauthenticated($request);
        }

        $lang = $this->language($request, $customer);
        $parsed = DeliveryGroup::parseNumber($number);

        if (!$parsed || (int) $parsed['subscription_id'] !== (int) $customer->subscription_id) {
            return $this->notFound($lang);
        }

        $date = Carbon::parse($parsed['date']);
        $delivery = $this->deliveries($customer, $date, $date, $lang)
            ->first(fn ($d) => strcasecmp($d['number'], DeliveryGroup::number($parsed['subscription_id'], $date, $parsed['index'])) === 0);

        return $delivery
            ? response()->json(['success' => true, 'delivery' => $delivery])
            : $this->notFound($lang);
    }

    /*
    |--------------------------------------------------------------------------
    | DATA
    |--------------------------------------------------------------------------
    */

    /**
     * @return Collection<int, array>
     */
    private function deliveries(CustomerAccount $customer, Carbon $from, Carbon $to, string $lang): Collection
    {
        if (!$customer->subscription_id) {
            return collect();
        }

        $orders = DeliveryGroup::ordersQuery()
            ->where('planned_orders.subscription_id', $customer->subscription_id)
            ->whereDate('planned_orders.scheduled_for', '>=', $from->toDateString())
            ->whereDate('planned_orders.scheduled_for', '<=', $to->toDateString())
            ->with(['animal', 'items.recipe'])
            ->orderBy('planned_orders.scheduled_for')
            ->orderBy('planned_orders.id')
            ->get();

        if ($orders->isEmpty()) {
            return collect();
        }

        $numbers = DeliveryGroup::numbers($orders->groupBy('delivery_key')->map->first()->values());

        // JAAK delivery of each order
        $jaakByOrder = [];
        JaakDelivery::whereHas('plannedOrders', fn ($q) => $q->whereIn('planned_orders.id', $orders->pluck('id')))
            ->with('plannedOrders:id')
            ->get()
            ->each(function (JaakDelivery $delivery) use (&$jaakByOrder) {
                foreach ($delivery->plannedOrders as $order) {
                    $jaakByOrder[$order->id] = $delivery;
                }
            });

        $today = Carbon::today()->toDateString();

        return $orders->groupBy('delivery_key')->map(function (Collection $group, string $key) use ($numbers, $jaakByOrder, $lang, $today) {
            /** @var PlannedOrder $first */
            $first = $group->first();
            $jaak = collect($group)->map(fn ($o) => $jaakByOrder[$o->id] ?? null)->filter()->first();

            $step = $this->step($group, $jaak);
            $stepIndex = array_search($step, self::STEPS, true);

            $times = [
                'scheduled' => null,
                'prepared' => $group->pluck('prepared_at')->filter()->max(),
                'on_the_way' => $jaak?->picked_up_at ?? $group->pluck('shipped_at')->filter()->max(),
                'delivered' => $jaak?->delivered_at ?? $group->pluck('delivered_at')->filter()->max(),
            ];

            return [
                'number' => $numbers[$key] ?? DeliveryGroup::number($first->subscription_id, $first->scheduled_for),
                'date' => $first->scheduled_for?->toDateString(),
                'is_today' => $first->scheduled_for?->toDateString() === $today,
                'delivery_slot' => $first->delivery_slot_resolved ?: null,
                'step' => $step,
                'step_label' => self::LABELS[$lang][$step],
                'steps' => collect(self::STEPS)->map(fn ($s, $i) => [
                    'key' => $s,
                    'label' => self::LABELS[$lang][$s],
                    'done' => $i <= $stepIndex,
                    'current' => $i === $stepIndex,
                    'at' => $times[$s] ? Carbon::parse($times[$s])->toIso8601String() : null,
                ])->values(),
                'pets' => $group->map(fn (PlannedOrder $o) => [
                    'order_id' => $o->id,
                    'pet_id' => $o->animal_id,
                    'name' => $o->animal?->name,
                    'species' => $o->animal?->species,
                    'recipes' => $o->items->map(fn ($item) => [
                        'name' => $item->recipe?->name,
                        'quantity' => (int) $item->quantity,
                    ])->values(),
                ])->values(),
                'recipes_count' => (int) $group->sum(fn ($o) => $o->items->sum('quantity')),
            ];
        })->values();
    }

    /**
     * Step of a delivery: the least advanced pet decides.
     * on_the_way: JAAK driver has the parcel (DROPOFF), or "shipped"
     * without JAAK (delivered by our own driver).
     */
    private function step(Collection $orders, ?JaakDelivery $jaak): string
    {
        $statuses = $orders->pluck('status');

        if ($statuses->every(fn ($s) => $s === 'delivered') || $jaak?->status === 'DELIVERED') {
            return 'delivered';
        }

        $allPrepared = $statuses->every(fn ($s) => in_array($s, ['prepared', 'shipped', 'delivered'], true));

        if (!$allPrepared) {
            return 'scheduled';
        }

        if ($jaak) {
            return $jaak->status === 'DROPOFF' ? 'on_the_way' : 'prepared';
        }

        return $statuses->every(fn ($s) => in_array($s, ['shipped', 'delivered'], true)) ? 'on_the_way' : 'prepared';
    }

    /*
    |--------------------------------------------------------------------------
    | AUTH (same as the other mobile controllers)
    |--------------------------------------------------------------------------
    */

    private function customer(Request $request): ?CustomerAccount
    {
        $token = $request->bearerToken() ?? $request->input('access_token');

        if (!$token) {
            return null;
        }

        $customer = CustomerAccount::where('access_token_hash', hash('sha256', $token))->first();

        if (!$customer) {
            return null;
        }

        if ($customer->access_token_expires_at && Carbon::parse($customer->access_token_expires_at)->isPast()) {
            return null;
        }

        return $customer;
    }

    private function language(Request $request, CustomerAccount $customer): string
    {
        $settings = is_array($customer->settings) ? $customer->settings : [];
        $lang = $request->input('lang') ?? ($settings['language'] ?? 'en');

        return $lang === 'ar' ? 'ar' : 'en';
    }

    private function unauthenticated(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $request->input('lang') === 'ar' ? 'يرجى تسجيل الدخول.' : 'Unauthenticated.',
        ], 401);
    }

    private function notFound(string $lang): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $lang === 'ar' ? 'الطلب غير موجود.' : 'Delivery not found.',
        ], 404);
    }
}
