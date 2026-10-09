<?php

namespace App\Http\Controllers;

use App\Models\CustomerAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class MobileNotificationDismissController extends Controller
{
    public function dismiss(
        Request $request,
        int $notification
    ): JsonResponse {

        try {

            $accessToken = trim(
                (string) $request->input(
                    'access_token',
                    ''
                )
            );

            $deviceId = trim(
                (string) $request->input(
                    'device_id',
                    ''
                )
            );


            /*
            |--------------------------------------------------------------------------
            | Resolve customer from access token
            |--------------------------------------------------------------------------
            */

            $customerAccount = null;


            if ($accessToken !== '') {

                $customerAccount =
                    CustomerAccount::query()
                        ->where(
                            'access_token_hash',
                            hash(
                                'sha256',
                                $accessToken
                            )
                        )
                        ->where(
                            function ($query) {

                                $query
                                    ->whereNull(
                                        'access_token_expires_at'
                                    )
                                    ->orWhere(
                                        'access_token_expires_at',
                                        '>',
                                        now()
                                    );
                            }
                        )
                        ->first();
            }


            /*
            |--------------------------------------------------------------------------
            | Resolve current device
            |--------------------------------------------------------------------------
            */

            $device = null;


            if ($deviceId !== '') {

                $device =
                    DB::table(
                        'mobile_devices'
                    )
                        ->where(
                            'device_id',
                            $deviceId
                        )
                        ->first();
            }


            /*
            |--------------------------------------------------------------------------
            | Fallback customer from device
            |--------------------------------------------------------------------------
            |
            | Si le token n'est pas résolu mais que le device
            | est déjà attaché à un customer, on utilise ce customer.
            |
            */

            if (
                !$customerAccount
                &&
                $device
                &&
                !empty(
                    $device->customer_account_id
                )
            ) {

                $customerAccount =
                    CustomerAccount::query()
                        ->find(
                            $device->customer_account_id
                        );
            }


            /*
            |--------------------------------------------------------------------------
            | Check notification
            |--------------------------------------------------------------------------
            */

            $notificationExists =
                DB::table(
                    'mobile_notifications'
                )
                    ->where(
                        'id',
                        $notification
                    )
                    ->exists();


            if (!$notificationExists) {

                return response()->json([
                    'success' => false,

                    'message' =>
                        'Notification not found.',
                ], 404);
            }


            /*
            |--------------------------------------------------------------------------
            | CUSTOMER MODE
            |--------------------------------------------------------------------------
            |
            | Customer identifié :
            |
            | on dismiss la notification pour :
            |
            | - toutes les lignes customer_account_id = customer
            | - toutes les lignes liées à ses devices
            |
            */

            if ($customerAccount) {

                /*
                 * Tous les devices attachés à ce customer.
                 */

                $customerDeviceIds =
                    DB::table(
                        'mobile_devices'
                    )
                        ->where(
                            'customer_account_id',
                            $customerAccount->id
                        )
                        ->pluck(
                            'id'
                        )
                        ->values()
                        ->all();


                /*
                 * Ajouter aussi le device courant au cas où.
                 */

                if (
                    $device
                    &&
                    !in_array(
                        $device->id,
                        $customerDeviceIds,
                        true
                    )
                ) {

                    $customerDeviceIds[] =
                        $device->id;
                }


                /*
                |--------------------------------------------------------------------------
                | Find all matching recipients
                |--------------------------------------------------------------------------
                */

                $recipientQuery =
                    DB::table(
                        'mobile_notification_recipients'
                    )
                        ->where(
                            'notification_id',
                            $notification
                        )
                        ->where(
                            function ($query) use (
                                $customerAccount,
                                $customerDeviceIds
                            ) {

                                /*
                                 * Direct customer match.
                                 */

                                $query
                                    ->where(
                                        'customer_account_id',
                                        $customerAccount->id
                                    );


                                /*
                                 * OR any device owned by customer.
                                 */

                                if (
                                    !empty(
                                        $customerDeviceIds
                                    )
                                ) {

                                    $query
                                        ->orWhereIn(
                                            'mobile_device_id',
                                            $customerDeviceIds
                                        );
                                }
                            }
                        );


                /*
                 * Check existence.
                 */

                $matchingRecipients =
                    (clone $recipientQuery)
                        ->count();


                if (
                    $matchingRecipients === 0
                ) {

                    return response()->json([
                        'success' => false,

                        'message' =>
                            'Notification recipient not found.',
                    ], 404);
                }


                /*
                |--------------------------------------------------------------------------
                | Dismiss all matching recipients
                |--------------------------------------------------------------------------
                */

                $dismissedAt =
                    now();


                $updatedRows =
                    $recipientQuery
                        ->whereNull(
                            'dismissed_at'
                        )
                        ->update([
                            'dismissed_at' =>
                                $dismissedAt,

                            'updated_at' =>
                                $dismissedAt,
                        ]);


                return response()->json([
                    'success' => true,

                    'message' =>
                        'Notification removed for customer.',

                    'dismissed_at' =>
                        $dismissedAt
                            ->toIso8601String(),

                    'customer_account_id' =>
                        $customerAccount->id,

                    'updated_recipients' =>
                        $updatedRows,

                    'device_ids' =>
                        $customerDeviceIds,
                ]);
            }


            /*
            |--------------------------------------------------------------------------
            | ANONYMOUS DEVICE MODE
            |--------------------------------------------------------------------------
            */

            if (!$device) {

                return response()->json([
                    'success' => false,

                    'message' =>
                        'Unable to identify notification recipient.',
                ], 401);
            }


            $recipient =
                DB::table(
                    'mobile_notification_recipients'
                )
                    ->where(
                        'notification_id',
                        $notification
                    )
                    ->where(
                        'mobile_device_id',
                        $device->id
                    )
                    ->first();


            if (!$recipient) {

                return response()->json([
                    'success' => false,

                    'message' =>
                        'Notification recipient not found.',
                ], 404);
            }


            /*
            |--------------------------------------------------------------------------
            | Already dismissed
            |--------------------------------------------------------------------------
            */

            if ($recipient->dismissed_at) {

                return response()->json([
                    'success' => true,

                    'message' =>
                        'Notification already removed.',

                    'dismissed_at' =>
                        $recipient->dismissed_at,

                    'updated_recipients' =>
                        0,
                ]);
            }


            /*
            |--------------------------------------------------------------------------
            | Dismiss only this anonymous device recipient
            |--------------------------------------------------------------------------
            */

            $dismissedAt =
                now();


            DB::table(
                'mobile_notification_recipients'
            )
                ->where(
                    'id',
                    $recipient->id
                )
                ->update([
                    'dismissed_at' =>
                        $dismissedAt,

                    'updated_at' =>
                        $dismissedAt,
                ]);


            return response()->json([
                'success' => true,

                'message' =>
                    'Notification removed for device.',

                'dismissed_at' =>
                    $dismissedAt
                        ->toIso8601String(),

                'updated_recipients' =>
                    1,
            ]);


        } catch (
            Throwable $exception
        ) {

            report(
                $exception
            );


            return response()->json([
                'success' => false,

                'message' =>
                    'Unable to remove notification.',
            ], 500);
        }
    }
}