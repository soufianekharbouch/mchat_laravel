<?php

namespace App\Http\Controllers;

use App\Models\CustomerAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CustomerDashboardController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | CUSTOMER DASHBOARD PAGE
    |--------------------------------------------------------------------------
    */

    public function index(Request $request)
    {
        $locale = $this->resolveLocale($request);

        App::setLocale($locale);

        return response()
            ->view(
                'customer_dashboard.home',
                [
                    'locale' => $locale,
                ]
            )
            ->header(
                'Content-Type',
                'application/liquid; charset=UTF-8'
            );
    }


    /*
    |--------------------------------------------------------------------------
    | MOBILE DASHBOARD DATA
    |--------------------------------------------------------------------------
    */

    public function data(Request $request): JsonResponse
    {
        try {
            /*
            |--------------------------------------------------------------------------
            | AUTHENTICATE CUSTOMER
            |--------------------------------------------------------------------------
            */

            $customerAccount =
                $this->resolveCustomerAccount(
                    $request
                );

            if (!$customerAccount) {
                return response()->json([
                    'success' => false,

                    'message' =>
                        'Your session is invalid or has expired.',
                ], 401);
            }


            /*
            |--------------------------------------------------------------------------
            | LOAD SUBSCRIPTION
            |--------------------------------------------------------------------------
            */

            $subscription =
                $customerAccount
                    ->subscription()
                    ->with([
                        'animals.mealDates.recipe',
                        'animals.plannedOrders.items.recipe',
                        'plannedOrders.animal',
                        'plannedOrders.items.recipe',
                        'customerServiceReports',
                        'loyaltyPointTransactions',
                    ])
                    ->first();

            if (!$subscription) {
                return response()->json([
                    'success' => false,

                    'message' =>
                        'No subscription is linked to this account.',
                ], 404);
            }


            /*
            |--------------------------------------------------------------------------
            | ANIMALS / PETS
            |--------------------------------------------------------------------------
            */

            $animals =
                $subscription
                    ->animals
                    ->map(function ($animal) {

                        /*
                        |--------------------------------------------------------------------------
                        | PET PHOTO URL
                        |--------------------------------------------------------------------------
                        */

                        $photoUrl = null;

                        if (!empty($animal->photo_path)) {
                                  $photoUrl = url(
                                'storage/app/public/' . $animal->photo_path
                            );
                        }


                        return [
                            'id' =>
                                $animal->id,

                            'name' =>
                                $animal->name,

                            'species' =>
                                $animal->species,

                            'breed_size_category' =>
                                $animal->breed_size_category,

                            'breed' =>
                                $animal->breed,

                            'weight_kg' =>
                                $animal->weight_kg,

                            'sex' =>
                                $animal->sex,

                            'allergies' =>
                                $animal->allergies,

                            'preferences' =>
                                $animal->preferences,

                            'age' =>
                                $animal->age,

                            'health_status' =>
                                $animal->health_status,

                            'note' =>
                                $animal->note,

                            /*
                            |--------------------------------------------------------------------------
                            | PHOTO
                            |--------------------------------------------------------------------------
                            */

                            'photo_url' =>
                                $photoUrl,

                            /*
                            |--------------------------------------------------------------------------
                            | SUBSCRIPTION INFORMATION
                            |--------------------------------------------------------------------------
                            */

                            'transition' =>
                                (bool) $animal->transition,

                            'subscription_start' =>
                                optional(
                                    $animal->subscription_start
                                )->format('Y-m-d'),

                            'subscription_end' =>
                                optional(
                                    $animal->subscription_end
                                )->format('Y-m-d'),

                            'subscription_status' =>
                                $animal->subscription_status,

                            'orders' =>
                                $animal->plannedOrders
                                    ->sortByDesc(fn ($order) =>
                                        optional($order->scheduled_for)->format('Y-m-d') ?? ''
                                    )
                                    ->map(fn ($order) => $this->serializeOrder($order))
                                    ->values(),

                            'next_order' =>
                                ($nextOrder = $animal->plannedOrders
                                    ->filter(fn ($order) =>
                                        $order->scheduled_for
                                        && $order->scheduled_for->copy()->startOfDay()->gte(now()->startOfDay())
                                        && in_array(
                                            (string) ($order->status ?? 'planned'),
                                            ['planned', 'prepared'],
                                            true
                                        )
                                    )
                                    ->sortBy(fn ($order) => $order->scheduled_for->format('Y-m-d'))
                                    ->first())
                                    ? $this->serializeOrder($nextOrder)
                                    : null,
                        ];
                    })
                    ->values();


            /*
            |--------------------------------------------------------------------------
            | ORDERS
            |--------------------------------------------------------------------------
            */

            $orders =
                $subscription
                    ->plannedOrders
                    ->map(function ($order) {

                        return [
                            'id' =>
                                $order->id,

                            'scheduled_for' =>
                                optional(
                                    $order->scheduled_for
                                )->format('Y-m-d'),

                            'status' =>
                                (string) (
                                    $order->status
                                    ?? 'planned'
                                ),

                            /*
                            |--------------------------------------------------------------------------
                            | DASHBOARD STATUS
                            |--------------------------------------------------------------------------
                            */

                            'dashboard_status' =>
                                $order->effective_status,

                            /*
                            |--------------------------------------------------------------------------
                            | ORDER DATES
                            |--------------------------------------------------------------------------
                            */

                            'prepared_at' =>
                                optional(
                                    $order->prepared_at
                                )->toIso8601String(),

                            'shipped_at' =>
                                optional(
                                    $order->shipped_at
                                )->toIso8601String(),

                            'delivered_at' =>
                                optional(
                                    $order->delivered_at
                                )->toIso8601String(),


                            /*
                            |--------------------------------------------------------------------------
                            | PET
                            |--------------------------------------------------------------------------
                            */

                            'animal' =>
                                $order->animal
                                    ? [
                                        'id' =>
                                            $order->animal->id,

                                        'name' =>
                                            $order->animal->name,

                                        'species' =>
                                            $order->animal->species,

                                        'photo_url' =>
                                            !empty(
                                                $order
                                                    ->animal
                                                    ->photo_path
                                            )
                                                ? url(
                                                    '/storage/app/public/' .
                                                    ltrim(
                                                        $order->animal->photo_path,
                                                        '/'
                                                    )
                                                )
                                                : null,
                                    ]
                                    : null,


                            /*
                            |--------------------------------------------------------------------------
                            | ORDER ITEMS
                            |--------------------------------------------------------------------------
                            */

                            'items' =>
                                $order
                                    ->items
                                    ->map(function ($item) {

                                        return [
                                            'id' =>
                                                $item->id,

                                            'quantity' =>
                                                (int) $item->quantity,

                                            'recipe_id' =>
                                                $item->recipe_id,

                                            'recipe' =>
                                                $item->recipe
                                                    ? [
                                                        'id' =>
                                                            $item
                                                                ->recipe
                                                                ->id,

                                                        'name' =>
                                                            $item
                                                                ->recipe
                                                                ->name,
                                                    ]
                                                    : null,
                                        ];
                                    })
                                    ->values(),
                        ];
                    })
                    ->sortByDesc(
                        function ($order) {
                            return
                                $order['scheduled_for']
                                ?? '';
                        }
                    )
                    ->values();


            /*
            |--------------------------------------------------------------------------
            | RESPONSE
            |--------------------------------------------------------------------------
            */

            return response()->json([
                'success' => true,


                /*
                |--------------------------------------------------------------------------
                | CUSTOMER
                |--------------------------------------------------------------------------
                */

                'customer' => [
                    'id' =>
                        $customerAccount->id,

                    'phone' =>
                        $customerAccount->phone,
                ],


                /*
                |--------------------------------------------------------------------------
                | SUBSCRIPTION
                |--------------------------------------------------------------------------
                */

                'subscription' => [
                    'id' =>
                        $subscription->id,

                    'first_name' =>
                        $subscription
                            ->subscriber_first_name,

                    'last_name' =>
                        $subscription
                            ->subscriber_last_name,

                    'valid_loyalty_points' =>
                        (int) (
                            $subscription
                                ->valid_loyalty_points
                            ?? 0
                        ),

                    /*
                    |--------------------------------------------------------------------------
                    | PETS
                    |--------------------------------------------------------------------------
                    */

                    'animals' =>
                        $animals,


                    /*
                    |--------------------------------------------------------------------------
                    | ORDERS
                    |--------------------------------------------------------------------------
                    */

                    'orders' =>
                        $orders,
                ],
            ], 200);

        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,

                'message' =>
                    'An internal server error occurred while loading the dashboard.',
            ], 500);
        }
    }


    private function serializeOrder($order): array
    {
        return [
            'id' => $order->id,
            'scheduled_for' => optional($order->scheduled_for)->format('Y-m-d'),
            'status' => (string) ($order->status ?? 'planned'),
            'dashboard_status' => $order->effective_status,
            'prepared_at' => optional($order->prepared_at)->toIso8601String(),
            'shipped_at' => optional($order->shipped_at)->toIso8601String(),
            'delivered_at' => optional($order->delivered_at)->toIso8601String(),
            'items' => $order->items
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'recipe_id' => $item->recipe_id,
                        'quantity' => (int) $item->quantity,
                        'recipe' => $item->recipe
                            ? [
                                'id' => $item->recipe->id,
                                'name' => $item->recipe->name,
                            ]
                            : null,
                    ];
                })
                ->values(),
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | RESOLVE AUTHENTICATED CUSTOMER
    |--------------------------------------------------------------------------
    */

    private function resolveCustomerAccount(
        Request $request
    ): ?CustomerAccount {
        $plainToken =
            trim(
                (string) $request->input(
                    'access_token',
                    ''
                )
            );

        if ($plainToken === '') {
            return null;
        }

        return CustomerAccount::query()
            ->where(
                'access_token_hash',
                hash(
                    'sha256',
                    $plainToken
                )
            )
            ->whereNotNull(
                'access_token_expires_at'
            )
            ->where(
                'access_token_expires_at',
                '>',
                now()
            )
            ->first();
    }


    /*
    |--------------------------------------------------------------------------
    | RESOLVE LOCALE
    |--------------------------------------------------------------------------
    */

    private function resolveLocale(
        Request $request
    ): string {
        $requestedLocale =
            (string) $request->query(
                'locale',
                'en'
            );

        $locale =
            strtolower(
                substr(
                    trim(
                        $requestedLocale
                    ),
                    0,
                    2
                )
            );

        return in_array(
            $locale,
            [
                'en',
                'ar',
            ],
            true
        )
            ? $locale
            : 'en';
    }
}