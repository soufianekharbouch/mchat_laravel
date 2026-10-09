<?php

namespace App\Http\Controllers;

use App\Models\CustomerAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

class AppCommunicationCustomerController extends Controller
{
    public function index(
        Request $request
    ) {
        /*
        |--------------------------------------------------------------------------
        | AVAILABLE TRACKING COLUMNS
        |--------------------------------------------------------------------------
        |
        | login_count / total_app_seconds may not exist yet on older databases.
        | We therefore detect them before using them for sorting.
        |
        */

        $hasLoginCount =
            Schema::hasColumn(
                'customer_accounts',
                'login_count'
            );

        $hasTotalAppSeconds =
            Schema::hasColumn(
                'customer_accounts',
                'total_app_seconds'
            );


        /*
        |--------------------------------------------------------------------------
        | BASE QUERY
        |--------------------------------------------------------------------------
        */

        $query =
            CustomerAccount::query()
                ->leftJoin(
                    'subscriptions',
                    'subscriptions.id',
                    '=',
                    'customer_accounts.subscription_id'
                )
                ->select(
                    'customer_accounts.*'
                )

                /*
                 * Real number of planned orders
                 * attached to the customer's subscription.
                 */
                ->selectSub(
                    function ($ordersQuery) {
                        $ordersQuery
                            ->from(
                                'planned_orders'
                            )
                            ->selectRaw(
                                'COUNT(*)'
                            )
                            ->whereColumn(
                                'planned_orders.subscription_id',
                                'customer_accounts.subscription_id'
                            );
                    },
                    'orders_count'
                )

                /*
                 * Values used directly by the Blade.
                 */
                ->addSelect([
                    'subscriptions.subscriber_first_name as subscription_first_name',
                    'subscriptions.subscriber_last_name as subscription_last_name',
                    'subscriptions.subscriber_phone as subscription_phone',
                    'subscriptions.creation_date as subscription_creation_date',
                    'subscriptions.created_at as subscription_created_at_fallback',
                    'subscriptions.code as subscription_code',
                ]);


        /*
        |--------------------------------------------------------------------------
        | SEARCH
        |--------------------------------------------------------------------------
        */

        $search =
            trim(
                (string) $request->input(
                    'search',
                    ''
                )
            );

        if ($search !== '') {

            $query->where(
                function ($customerQuery) use (
                    $search
                ) {
                    $customerQuery
                        ->where(
                            'customer_accounts.phone',
                            'like',
                            '%' . $search . '%'
                        )
                        ->orWhere(
                            'customer_accounts.id',
                            $search
                        )
                        ->orWhere(
                            'customer_accounts.subscription_id',
                            $search
                        )
                        ->orWhere(
                            'subscriptions.subscriber_first_name',
                            'like',
                            '%' . $search . '%'
                        )
                        ->orWhere(
                            'subscriptions.subscriber_last_name',
                            'like',
                            '%' . $search . '%'
                        )
                        ->orWhere(
                            DB::raw(
                                "CONCAT_WS(' ', subscriptions.subscriber_first_name, subscriptions.subscriber_last_name)"
                            ),
                            'like',
                            '%' . $search . '%'
                        )
                        ->orWhere(
                            'subscriptions.subscriber_phone',
                            'like',
                            '%' . $search . '%'
                        )
                        ->orWhere(
                            'subscriptions.code',
                            'like',
                            '%' . $search . '%'
                        );
                }
            );
        }


        /*
        |--------------------------------------------------------------------------
        | PASSWORD STATUS FILTER
        |--------------------------------------------------------------------------
        */

        $passwordStatus =
            (string) $request->input(
                'password_status',
                ''
            );

        if ($passwordStatus === 'initial') {

            $query
                ->whereNull(
                    'customer_accounts.password_changed_at'
                );

        } elseif (
            $passwordStatus === 'personal'
        ) {

            $query
                ->whereNotNull(
                    'customer_accounts.password_changed_at'
                );
        }


        /*
        |--------------------------------------------------------------------------
        | SORTING
        |--------------------------------------------------------------------------
        */

        $sort =
            (string) $request->input(
                'sort',
                'id'
            );

        $direction =
            strtolower(
                (string) $request->input(
                    'direction',
                    'desc'
                )
            );

        if (
            !in_array(
                $direction,
                [
                    'asc',
                    'desc',
                ],
                true
            )
        ) {
            $direction = 'desc';
        }


        switch ($sort) {

            /*
             * Alphabetical by first name, then last name.
             */
            case 'name':

                $query
                    ->orderByRaw(
                        "COALESCE(NULLIF(TRIM(subscriptions.subscriber_first_name), ''), '') {$direction}"
                    )
                    ->orderByRaw(
                        "COALESCE(NULLIF(TRIM(subscriptions.subscriber_last_name), ''), '') {$direction}"
                    )
                    ->orderBy(
                        'customer_accounts.id',
                        $direction
                    );

                break;


            case 'password_status':

                /*
                 * NULL password_changed_at = initial password.
                 * Non-NULL = personal password.
                 */
                $query
                    ->orderByRaw(
                        "CASE WHEN customer_accounts.password_changed_at IS NULL THEN 0 ELSE 1 END {$direction}"
                    )
                    ->orderByDesc(
                        'customer_accounts.id'
                    );

                break;


            case 'logins':

                if ($hasLoginCount) {

                    $query
                        ->orderBy(
                            'customer_accounts.login_count',
                            $direction
                        );

                } else {

                    $query
                        ->orderByDesc(
                            'customer_accounts.id'
                        );
                }

                break;


            case 'app_time':

                if ($hasTotalAppSeconds) {

                    $query
                        ->orderBy(
                            'customer_accounts.total_app_seconds',
                            $direction
                        );

                } else {

                    $query
                        ->orderByDesc(
                            'customer_accounts.id'
                        );
                }

                break;


            case 'last_login':

                $query
                    ->orderByRaw(
                        "customer_accounts.last_login_at IS NULL ASC"
                    )
                    ->orderBy(
                        'customer_accounts.last_login_at',
                        $direction
                    );

                break;


            case 'orders':

                /*
                 * Alias generated by selectSub().
                 * Default direction from the Blade is DESC,
                 * so customers with most orders appear first.
                 */
                $query
                    ->orderBy(
                        'orders_count',
                        $direction
                    )
                    ->orderByDesc(
                        'customer_accounts.id'
                    );

                break;


            case 'seniority':

                /*
                 * Oldest subscriptions first when direction = ASC.
                 */
                $query
                    ->orderByRaw(
                        "COALESCE(subscriptions.creation_date, subscriptions.created_at) IS NULL ASC"
                    )
                    ->orderByRaw(
                        "COALESCE(subscriptions.creation_date, subscriptions.created_at) {$direction}"
                    )
                    ->orderBy(
                        'customer_accounts.id',
                        'asc'
                    );

                break;


            default:

                $query
                    ->orderByDesc(
                        'customer_accounts.id'
                    );

                break;
        }


        /*
        |--------------------------------------------------------------------------
        | PAGINATION
        |--------------------------------------------------------------------------
        */

        $customers =
            $query
                ->paginate(
                    20
                )
                ->withQueryString();


        /*
        |--------------------------------------------------------------------------
        | DISPLAY VALUES
        |--------------------------------------------------------------------------
        */

        $customers
            ->getCollection()
            ->transform(
                function (
                    CustomerAccount $customer
                ) use (
                    $hasLoginCount,
                    $hasTotalAppSeconds
                ) {
                    /*
                     * Full name.
                     */
                    $customer->full_name =
                        trim(
                            (
                                $customer
                                    ->subscription_first_name
                                ?? ''
                            )
                            . ' '
                            . (
                                $customer
                                    ->subscription_last_name
                                ?? ''
                            )
                        );


                    /*
                     * Phone:
                     * subscription value first,
                     * customer_accounts fallback.
                     */
                    $customer->display_phone =
                        !empty(
                            $customer
                                ->subscription_phone
                        )
                            ? $customer
                                ->subscription_phone
                            : $customer
                                ->phone;


                    /*
                     * Subscription seniority date.
                     */
                    $creationDate =
                        $customer
                            ->subscription_creation_date
                        ?:
                        $customer
                            ->subscription_created_at_fallback;

                    $customer
                        ->subscription_created_at =
                            $creationDate
                                ? \Carbon\Carbon::parse(
                                    $creationDate
                                )
                                : null;


                    /*
                     * Login count.
                     */
                    if (!$hasLoginCount) {

                        $customer->login_count =
                            0;
                    }


                    /*
                     * Total app duration.
                     */
                    $totalSeconds =
                        $hasTotalAppSeconds
                            ? (int) (
                                $customer
                                    ->total_app_seconds
                                ?? 0
                            )
                            : 0;

                    if ($totalSeconds <= 0) {

                        $customer
                            ->formatted_app_duration =
                                '—';

                    } else {

                        $days =
                            intdiv(
                                $totalSeconds,
                                86400
                            );

                        $hours =
                            intdiv(
                                $totalSeconds % 86400,
                                3600
                            );

                        $minutes =
                            intdiv(
                                $totalSeconds % 3600,
                                60
                            );

                        $parts = [];

                        if ($days > 0) {
                            $parts[] =
                                $days . 'd';
                        }

                        if ($hours > 0) {
                            $parts[] =
                                $hours . 'h';
                        }

                        if (
                            $minutes > 0
                            ||
                            empty($parts)
                        ) {
                            $parts[] =
                                $minutes . 'm';
                        }

                        $customer
                            ->formatted_app_duration =
                                implode(
                                    ' ',
                                    $parts
                                );
                    }


                    /*
                     * Initial password (admin display only).
                     *
                     * It is available only while the customer
                     * has not changed the initial password.
                     */
                    $customer->initial_password_plain =
                        null;

                    if (
                        empty(
                            $customer->password_changed_at
                        )
                        &&
                        !empty(
                            $customer->initial_password_encrypted
                        )
                    ) {
                        try {

                            $customer->initial_password_plain =
                                Crypt::decryptString(
                                    $customer->initial_password_encrypted
                                );

                        } catch (
                            \Throwable $exception
                        ) {

                            /*
                             * Do not break the customer list if an old
                             * encrypted value cannot be decrypted.
                             */
                            report(
                                $exception
                            );

                            $customer->initial_password_plain =
                                null;
                        }
                    }


                    return $customer;
                }
            );


        return view(
            'app-communications.customers.index',
            compact(
                'customers'
            )
        );
    }
}
