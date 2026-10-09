<?php

namespace App\Http\Controllers;

use App\Models\CustomerAccount;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MobileDeviceController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | REGISTER / UPDATE DEVICE
    |--------------------------------------------------------------------------
    |
    | POST /api/mobile/device/register
    |
    | Cette route fonctionne :
    |
    | - avant login
    | - après login
    | - avec access_token expiré
    | - sans access_token
    |
    | Le device_id est l'identifiant permanent de l'installation.
    |
    */
public function detachCustomer(Request $request): JsonResponse
{
    $request->validate([
        'device_id' => [
            'required',
            'string',
            'max:255',
        ],
    ]);

    $deviceId = trim(
        (string) $request->input('device_id')
    );

    DB::table('mobile_devices')
        ->where('device_id', $deviceId)
        ->update([
            'customer_account_id' => null,
            'updated_at' => now(),
        ]);

    return response()->json([
        'success' => true,
        'message' => 'Customer detached from device.',
    ]);
}
    public function register(
        Request $request
    ): JsonResponse {

        /*
        |--------------------------------------------------------------------------
        | Validate basic fields
        |--------------------------------------------------------------------------
        */

        $validated =
            $request->validate([

                'device_id' =>
                    'required|string|max:255',

                'expo_push_token' =>
                    'nullable|string|max:255',

                'platform' =>
                    'required|in:android,ios',

                'locale' =>
                    'nullable|in:ar,en',

                'device_name' =>
                    'nullable|string|max:255',

                'access_token' =>
                    'nullable|string',

            ]);


        $deviceId =
            trim(
                (string)
                $validated['device_id']
            );


        $expoPushToken =
            isset(
                $validated['expo_push_token']
            )
                ? trim(
                    (string)
                    $validated['expo_push_token']
                )
                : null;


        if (
            $expoPushToken === ''
        ) {
            $expoPushToken = null;
        }


        $locale =
            $validated['locale']
            ?? 'en';


        /*
        |--------------------------------------------------------------------------
        | Resolve customer if valid token exists
        |--------------------------------------------------------------------------
        |
        | Important:
        |
        | Un token invalide ou expiré ne bloque PAS
        | l'enregistrement du device.
        |
        */

        $customerAccount =
            $this->resolveCustomerAccount(
                $request
            );


        try {

            /*
            |--------------------------------------------------------------------------
            | Find existing device
            |--------------------------------------------------------------------------
            */

            $device =
                DB::table(
                    'mobile_devices'
                )
                    ->where(
                        'device_id',
                        $deviceId
                    )
                    ->first();


            /*
            |--------------------------------------------------------------------------
            | Expo token may move from one installation to another
            |--------------------------------------------------------------------------
            |
            | Si le même expo_push_token existe déjà sur un autre device_id,
            | on le retire de l'ancien device.
            |
            */

            if ($expoPushToken) {

                DB::table(
                    'mobile_devices'
                )
                    ->where(
                        'expo_push_token',
                        $expoPushToken
                    )
                    ->where(
                        'device_id',
                        '<>',
                        $deviceId
                    )
                    ->update([

                        'expo_push_token' =>
                            null,

                        'updated_at' =>
                            now(),

                    ]);

            }


            /*
            |--------------------------------------------------------------------------
            | Existing device
            |--------------------------------------------------------------------------
            */

            if ($device) {

                $updateData = [

                    'platform' =>
                        $validated['platform'],

                    'locale' =>
                        $locale,

                    'device_name' =>
                        $validated['device_name']
                        ?? $device->device_name,

                    'is_active' =>
                        1,

                    'last_seen_at' =>
                        now(),

                    'updated_at' =>
                        now(),

                ];


                /*
                 * Only update push token when one exists.
                 *
                 * Example:
                 * user refuses permission.
                 *
                 * We don't necessarily want to erase
                 * an existing valid token because of
                 * a temporary frontend problem.
                 */

                if ($expoPushToken) {

                    $updateData[
                        'expo_push_token'
                    ] = $expoPushToken;

                }


                /*
                 * Customer association
                 *
                 * IMPORTANT:
                 * We only update customer_account_id
                 * if we have a valid access token.
                 *
                 * Therefore:
                 *
                 * session expires
                 * -> existing customer association stays.
                 */

                if ($customerAccount) {

                    $updateData[
                        'customer_account_id'
                    ] = $customerAccount->id;

                }


                DB::table(
                    'mobile_devices'
                )
                    ->where(
                        'id',
                        $device->id
                    )
                    ->update(
                        $updateData
                    );


                $updatedDevice =
                    DB::table(
                        'mobile_devices'
                    )
                        ->where(
                            'id',
                            $device->id
                        )
                        ->first();
/*
|--------------------------------------------------------------------------
| SEND PENDING CUSTOMER NOTIFICATIONS
|--------------------------------------------------------------------------
*/

if ($customerAccount) {

    try {

        app(
            MobileNotificationController::class
        )->sendPendingForCustomerDevice(
            $customerAccount->id,
            $updatedDevice->id
        );

    } catch (\Throwable $e) {

        /*
         * Une erreur push ne doit jamais
         * bloquer le login.
         */

        Log::error(
            'Unable to send pending notifications after device login.',
            [
                'customer_account_id' =>
                    $customerAccount->id,

                'device_id' =>
                    $updatedDevice->id,

                'message' =>
                    $e->getMessage(),
            ]
        );
    }
}

                return response()->json([

                    'success' =>
                        true,

                    'message' =>
                        'Device updated successfully.',

                    'device' => [

                        'id' =>
                            $updatedDevice->id,

                        'device_id' =>
                            $updatedDevice->device_id,

                        'customer_account_id' =>
                            $updatedDevice->customer_account_id,

                        'platform' =>
                            $updatedDevice->platform,

                        'locale' =>
                            $updatedDevice->locale,

                        'is_active' =>
                            (bool)
                            $updatedDevice->is_active,

                    ],

                ]);

            }


            /*
            |--------------------------------------------------------------------------
            | New device
            |--------------------------------------------------------------------------
            */

            $newDeviceId =
                DB::table(
                    'mobile_devices'
                )
                    ->insertGetId([

                        'device_id' =>
                            $deviceId,

                        'customer_account_id' =>
                            $customerAccount
                                ? $customerAccount->id
                                : null,

                        'expo_push_token' =>
                            $expoPushToken,

                        'platform' =>
                            $validated['platform'],

                        'locale' =>
                            $locale,

                        'device_name' =>
                            $validated['device_name']
                            ?? null,

                        'is_active' =>
                            1,

                        'last_seen_at' =>
                            now(),

                        'created_at' =>
                            now(),

                        'updated_at' =>
                            now(),

                    ]);


            $newDevice =
                DB::table(
                    'mobile_devices'
                )
                    ->where(
                        'id',
                        $newDeviceId
                    )
                    ->first();
if ($customerAccount) {

    try {

        app(
            MobileNotificationController::class
        )->sendPendingForCustomerDevice(
            $customerAccount->id,
            $newDevice->id
        );

    } catch (\Throwable $e) {

        Log::error(
            'Unable to send pending notifications after new device login.',
            [
                'customer_account_id' =>
                    $customerAccount->id,

                'device_id' =>
                    $newDevice->id,

                'message' =>
                    $e->getMessage(),
            ]
        );
    }
}

            return response()->json([

                'success' =>
                    true,

                'message' =>
                    'Device registered successfully.',

                'device' => [

                    'id' =>
                        $newDevice->id,

                    'device_id' =>
                        $newDevice->device_id,

                    'customer_account_id' =>
                        $newDevice->customer_account_id,

                    'platform' =>
                        $newDevice->platform,

                    'locale' =>
                        $newDevice->locale,

                    'is_active' =>
                        (bool)
                        $newDevice->is_active,

                ],

            ], 201);


        }catch (\Throwable $e) {

            Log::error(
                'Mobile device registration error.',
                [
                    'device_id' => $deviceId,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]
            );
        
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'debug' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
            ], 500);
        }

    }


    /*
    |--------------------------------------------------------------------------
    | UNREGISTER / DISABLE DEVICE
    |--------------------------------------------------------------------------
    |
    | POST /api/mobile/device/unregister
    |
    | Cette fonction NE DOIT PAS être appelée automatiquement au logout.
    |
    | Elle sert uniquement si :
    |
    | - user désactive les notifications
    | - device/token devient invalide
    | - option volontaire dans settings
    |
    */

    public function unregister(
        Request $request
    ): JsonResponse {

        $validated =
            $request->validate([

                'device_id' =>
                    'required|string|max:255',

                'access_token' =>
                    'nullable|string',

            ]);


        $deviceId =
            trim(
                (string)
                $validated['device_id']
            );


        $device =
            DB::table(
                'mobile_devices'
            )
                ->where(
                    'device_id',
                    $deviceId
                )
                ->first();


        if (!$device) {

            return response()->json([

                'success' =>
                    true,

                'message' =>
                    'Device already unregistered.',

            ]);

        }


        /*
         * We don't remove the row.
         *
         * Keeping the device record is useful
         * for notification history and analytics.
         */

        DB::table(
            'mobile_devices'
        )
            ->where(
                'id',
                $device->id
            )
            ->update([

                'is_active' =>
                    0,

                'updated_at' =>
                    now(),

            ]);


        return response()->json([

            'success' =>
                true,

            'message' =>
                'Device unregistered successfully.',

        ]);

    }


    /*
    |--------------------------------------------------------------------------
    | RESOLVE CUSTOMER ACCOUNT
    |--------------------------------------------------------------------------
    |
    | Mobile auth:
    |
    | access token côté application
    |        ↓
    | SHA-256
    |        ↓
    | recherche customer_accounts
    |
    */

private function resolveCustomerAccount(
    Request $request
): ?CustomerAccount {

    $accessToken = trim(
        (string) $request->input(
            'access_token',
            ''
        )
    );

    if ($accessToken === '') {
        return null;
    }

    $tokenHash = hash(
        'sha256',
        $accessToken
    );

    return CustomerAccount::query()
        ->where(
            'access_token_hash',
            $tokenHash
        )
        ->where(function ($query) {
            $query
                ->whereNull(
                    'access_token_expires_at'
                )
                ->orWhere(
                    'access_token_expires_at',
                    '>',
                    now()
                );
        })
        ->first();
}}