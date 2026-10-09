<?php

namespace App\Http\Controllers;

use App\Models\CustomerAccount;
use App\Models\CustomerAppActivity;
use Illuminate\Http\Request;

class AppCommunicationCustomerActivityController extends Controller
{
    public function index(
        Request $request,
        ?CustomerAccount $customer = null
    ) {
        /*
        |--------------------------------------------------------------------------
        | CUSTOMER FILTER
        |--------------------------------------------------------------------------
        |
        | Deux possibilités :
        |
        | /app-communications/activities
        |
        | ou
        |
        | /app-communications/customers/{customer}/activities
        |
        */

        $customerId =
            $customer
                ? $customer->id
                : $request->input(
                    'customer_id'
                );


        /*
        |--------------------------------------------------------------------------
        | OTHER FILTERS
        |--------------------------------------------------------------------------
        */

        $code =
            trim(
                (string) $request->input(
                    'code',
                    ''
                )
            );


        $dateFrom =
            trim(
                (string) $request->input(
                    'date_from',
                    ''
                )
            );


        $dateTo =
            trim(
                (string) $request->input(
                    'date_to',
                    ''
                )
            );


        $search =
            trim(
                (string) $request->input(
                    'search',
                    ''
                )
            );


        /*
        |--------------------------------------------------------------------------
        | BASE QUERY
        |--------------------------------------------------------------------------
        */

        $query =
            CustomerAppActivity::query()
                ->with([
                    'customerAccount.subscription',
                    'mobileDevice',
                ]);


        /*
        |--------------------------------------------------------------------------
        | FILTER BY CUSTOMER
        |--------------------------------------------------------------------------
        */

        if (
            $customerId !== null
            &&
            $customerId !== ''
        ) {

            $query->where(
                'customer_account_id',
                (int) $customerId
            );
        }


        /*
        |--------------------------------------------------------------------------
        | FILTER BY OPERATION CODE
        |--------------------------------------------------------------------------
        */

        if ($code !== '') {

            $query->where(
                'code',
                $code
            );
        }


        /*
        |--------------------------------------------------------------------------
        | FILTER BY DATE
        |--------------------------------------------------------------------------
        */

        if ($dateFrom !== '') {

            $query->whereDate(
                'created_at',
                '>=',
                $dateFrom
            );
        }


        if ($dateTo !== '') {

            $query->whereDate(
                'created_at',
                '<=',
                $dateTo
            );
        }


        /*
        |--------------------------------------------------------------------------
        | SEARCH CUSTOMER
        |--------------------------------------------------------------------------
        |
        | Search by:
        |
        | - account phone
        | - subscriber phone
        | - first name
        | - last name
        |
        */

        if ($search !== '') {

            $query->whereHas(
                'customerAccount',
                function (
                    $customerQuery
                ) use (
                    $search
                ) {

                    $customerQuery
                        ->where(
                            'phone',
                            'like',
                            '%' . $search . '%'
                        )

                        ->orWhereHas(
                            'subscription',
                            function (
                                $subscriptionQuery
                            ) use (
                                $search
                            ) {

                                $subscriptionQuery
                                    ->where(
                                        'subscriber_first_name',
                                        'like',
                                        '%' . $search . '%'
                                    )

                                    ->orWhere(
                                        'subscriber_last_name',
                                        'like',
                                        '%' . $search . '%'
                                    )

                                    ->orWhere(
                                        'subscriber_phone',
                                        'like',
                                        '%' . $search . '%'
                                    );
                            }
                        );
                }
            );
        }


        /*
        |--------------------------------------------------------------------------
        | ACTIVITIES
        |--------------------------------------------------------------------------
        */

        $activities =
            $query
                ->orderByDesc(
                    'created_at'
                )
                ->paginate(
                    50
                )
                ->withQueryString();


        /*
        |--------------------------------------------------------------------------
        | CUSTOMERS FOR FILTER
        |--------------------------------------------------------------------------
        */

        $customers =
            CustomerAccount::query()
                ->with(
                    'subscription'
                )
                ->orderBy(
                    'id'
                )
                ->get();


        /*
        |--------------------------------------------------------------------------
        | AVAILABLE ACTIVITY CODES
        |--------------------------------------------------------------------------
        */

        $activityCodes =
            CustomerAppActivity::query()
                ->select(
                    'code'
                )
                ->distinct()
                ->orderBy(
                    'code'
                )
                ->pluck(
                    'code'
                );


        /*
        |--------------------------------------------------------------------------
        | VIEW
        |--------------------------------------------------------------------------
        */

        return view(
            'app-communications.activities.index',
            compact(
                'activities',
                'customers',
                'activityCodes',
                'customer'
            )
        );
    }
}