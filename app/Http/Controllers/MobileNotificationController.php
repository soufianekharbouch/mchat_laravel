<?php

namespace App\Http\Controllers;

use App\Models\CustomerAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class MobileNotificationController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | MOBILE: LIST NOTIFICATIONS
    |--------------------------------------------------------------------------
    |
    | POST /api/mobile/notifications
    |
    | Identification possible avec :
    | - access_token
    | - device_id
    |
    */

    public function index(
        Request $request
    ): JsonResponse {
        $deviceId = trim(
            (string) $request->input(
                'device_id',
                ''
            )
        );

        $customerAccount =
            $this->resolveCustomerAccount(
                $request
            );

        $device = null;

        if ($deviceId !== '') {
            $device =
                DB::table('mobile_devices')
                    ->where(
                        'device_id',
                        $deviceId
                    )
                    ->first();
        }

        /*
         * Impossible d'identifier
         * le customer ou le device.
         */

        if (
            !$customerAccount
            &&
            !$device
        ) {
            return response()->json([
                'success' => true,
                'notifications' => [],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Query
        |--------------------------------------------------------------------------
        */

        $query =
            DB::table(
                'mobile_notification_recipients as r'
            )
                ->join(
                    'mobile_notifications as n',
                    'n.id',
                    '=',
                    'r.notification_id'
                )
                ->where(
                    'n.status',
                    'sent'
                )

                /*
                 * IMPORTANT :
                 * une notification supprimée
                 * par le customer ne doit plus
                 * apparaître dans l'application.
                 */
                ->whereNull(
                    'r.dismissed_at'
                );

        /*
         * Recipient correspondant :
         *
         * device actuel
         * OU
         * customer actuellement connecté.
         */

        $query->where(
            function ($q) use (
                $customerAccount,
                $device
            ) {
                $hasCondition = false;

                if ($device) {
                    $q->where(
                        'r.mobile_device_id',
                        $device->id
                    );

                    $hasCondition = true;
                }

                if ($customerAccount) {
                    if ($hasCondition) {
                        $q->orWhere(
                            'r.customer_account_id',
                            $customerAccount->id
                        );
                    } else {
                        $q->where(
                            'r.customer_account_id',
                            $customerAccount->id
                        );
                    }
                }
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Load rows
        |--------------------------------------------------------------------------
        */

        $rows =
            $query
                ->select([
                    'n.id',
                    'n.title_en',
                    'n.title_ar',
                    'n.body_en',
                    'n.body_ar',
                    'n.created_at',
                    'n.sent_at',

                    'r.viewed_at',
                    'r.dismissed_at',
                ])
                ->orderByDesc(
                    DB::raw(
                        'COALESCE(
                            n.sent_at,
                            n.created_at
                        )'
                    )
                )
                ->get();

        /*
        |--------------------------------------------------------------------------
        | Locale
        |--------------------------------------------------------------------------
        */

        $locale =
            $this->resolveLocale(
                $request,
                $device
            );

        /*
        |--------------------------------------------------------------------------
        | Format response
        |--------------------------------------------------------------------------
        */

        $notifications =
            $rows
                ->unique('id')
                ->values()
                ->map(
                    function ($row) use (
                        $locale
                    ) {
                        $isArabic =
                            $locale === 'ar';

                        return [
                            'id' =>
                                $row->id,

                            'title' =>
                                $isArabic
                                    ? (
                                        $row->title_ar
                                        ?: $row->title_en
                                    )
                                    : (
                                        $row->title_en
                                        ?: $row->title_ar
                                    ),

                            'body' =>
                                $isArabic
                                    ? (
                                        $row->body_ar
                                        ?: $row->body_en
                                    )
                                    : (
                                        $row->body_en
                                        ?: $row->body_ar
                                    ),

                            /*
                             * On garde les deux langues
                             * pour l'application.
                             */

                            'title_en' =>
                                $row->title_en,

                            'title_ar' =>
                                $row->title_ar,

                            'body_en' =>
                                $row->body_en,

                            'body_ar' =>
                                $row->body_ar,

                            'created_at' =>
                                $row->created_at,

                            'sent_at' =>
                                $row->sent_at,

                            'viewed_at' =>
                                $row->viewed_at,

                            'dismissed_at' =>
                                $row->dismissed_at,
                        ];
                    }
                );

        return response()->json([
            'success' => true,

            'notifications' =>
                $notifications,
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | MOBILE: UNREAD COUNT
    |--------------------------------------------------------------------------
    |
    | POST /api/mobile/notifications/unread-count
    |
    */

    public function unreadCount(
        Request $request
    ): JsonResponse {
        $deviceId = trim(
            (string) $request->input(
                'device_id',
                ''
            )
        );

        $customerAccount =
            $this->resolveCustomerAccount(
                $request
            );

        $device = null;

        if ($deviceId !== '') {
            $device =
                DB::table('mobile_devices')
                    ->where(
                        'device_id',
                        $deviceId
                    )
                    ->first();
        }

        if (
            !$customerAccount
            &&
            !$device
        ) {
            return response()->json([
                'success' => true,
                'unread_count' => 0,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Query
        |--------------------------------------------------------------------------
        */

        $query =
            DB::table(
                'mobile_notification_recipients as r'
            )
                ->join(
                    'mobile_notifications as n',
                    'n.id',
                    '=',
                    'r.notification_id'
                )
                ->where(
                    'n.status',
                    'sent'
                )
                ->whereNull(
                    'r.viewed_at'
                )

                /*
                 * Une notification supprimée
                 * ne compte plus dans le badge.
                 */
                ->whereNull(
                    'r.dismissed_at'
                );

        $query->where(
            function ($q) use (
                $customerAccount,
                $device
            ) {
                $hasCondition = false;

                if ($device) {
                    $q->where(
                        'r.mobile_device_id',
                        $device->id
                    );

                    $hasCondition = true;
                }

                if ($customerAccount) {
                    if ($hasCondition) {
                        $q->orWhere(
                            'r.customer_account_id',
                            $customerAccount->id
                        );
                    } else {
                        $q->where(
                            'r.customer_account_id',
                            $customerAccount->id
                        );
                    }
                }
            }
        );

        /*
         * Une notification peut avoir
         * plusieurs lignes recipient.
         *
         * On compte une seule notification.
         */

        $count =
            $query
                ->distinct()
                ->count(
                    'n.id'
                );

        return response()->json([
            'success' => true,

            'unread_count' =>
                $count,
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | MOBILE: MARK AS VIEWED
    |--------------------------------------------------------------------------
    |
    | POST /api/mobile/notifications/{notification}/view
    |
    */

public function markViewed(
    Request $request,
    int $notification
): JsonResponse {
    try {

        $deviceId = trim(
            (string) $request->input(
                'device_id',
                ''
            )
        );

        $customerAccount =
            $this->resolveCustomerAccount(
                $request
            );

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
        | IDENTIFICATION
        |--------------------------------------------------------------------------
        */

        if (
            !$customerAccount
            &&
            !$device
        ) {
            return response()->json([
                'success' => false,

                'message' =>
                    'Device or customer not found.',
            ], 404);
        }


        /*
        |--------------------------------------------------------------------------
        | LOAD NOTIFICATION
        |--------------------------------------------------------------------------
        */

        $notificationRow =
            DB::table(
                'mobile_notifications'
            )
                ->where(
                    'id',
                    $notification
                )
                ->where(
                    'status',
                    'sent'
                )
                ->first();


        if (!$notificationRow) {
            return response()->json([
                'success' => false,

                'message' =>
                    'Notification not found.',
            ], 404);
        }


        /*
        |--------------------------------------------------------------------------
        | ANONYMOUS CUSTOMER
        |--------------------------------------------------------------------------
        |
        | Cas spécial :
        |
        | seulement target_type = all_devices
        |
        | Le clic Android peut être tracké
        | uniquement avec le device.
        |
        */

        if (!$customerAccount) {

            /*
             * Anonymous tracking autorisé
             * uniquement pour all_devices.
             */

            if (
                $notificationRow->target_type
                !== 'all_devices'
            ) {
                return response()->json([
                    'success' => false,

                    'message' =>
                        'Customer authentication required.',
                ], 401);
            }


            if (!$device) {
                return response()->json([
                    'success' => false,

                    'message' =>
                        'Device not found.',
                ], 404);
            }


            /*
             * Trouver exactement le recipient
             * correspondant au device qui a
             * reçu la notification.
             */

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
             * Déjà vue :
             * ne pas changer la date.
             */

            if ($recipient->viewed_at) {
                return response()->json([
                    'success' => true,

                    'viewed_at' =>
                        $recipient->viewed_at,
                ]);
            }


            /*
             * Première consultation anonymous.
             */

            $viewedAt =
                now();


            DB::table(
                'mobile_notification_recipients'
            )
                ->where(
                    'id',
                    $recipient->id
                )
                ->update([
                    'viewed_at' =>
                        $viewedAt,

                    'updated_at' =>
                        $viewedAt,
                ]);


            return response()->json([
                'success' => true,

                'viewed_at' =>
                    $viewedAt
                        ->toISOString(),
            ]);
        }


        /*
        |--------------------------------------------------------------------------
        | LOGGED CUSTOMER
        |--------------------------------------------------------------------------
        |
        | IMPORTANT :
        |
        | On revient ici à ton comportement
        | original qui fonctionnait :
        |
        | device OU customer.
        |
        */

        $recipientQuery =
            DB::table(
                'mobile_notification_recipients'
            )
                ->where(
                    'notification_id',
                    $notification
                );


        $recipientQuery->where(
            function ($q) use (
                $customerAccount,
                $device
            ) {

                $hasCondition = false;


                /*
                 * Device actuel.
                 */

                if ($device) {
                    $q->where(
                        'mobile_device_id',
                        $device->id
                    );

                    $hasCondition = true;
                }


                /*
                 * Customer connecté.
                 */

                if ($customerAccount) {

                    if ($hasCondition) {
                        $q->orWhere(
                            'customer_account_id',
                            $customerAccount->id
                        );
                    } else {
                        $q->where(
                            'customer_account_id',
                            $customerAccount->id
                        );
                    }
                }
            }
        );


        /*
        |--------------------------------------------------------------------------
        | CHECK RECIPIENT
        |--------------------------------------------------------------------------
        */

        $existingRecipient =
            (clone $recipientQuery)
                ->first();


        if (!$existingRecipient) {
            return response()->json([
                'success' => false,

                'message' =>
                    'Notification recipient not found.',
            ], 404);
        }


        /*
        |--------------------------------------------------------------------------
        | CLAIM ALL_DEVICES RECIPIENT
        |--------------------------------------------------------------------------
        |
        | Si la notification était all_devices
        | et que la ligne appartenait auparavant
        | à un device anonymous :
        |
        | on ajoute maintenant le customer
        | SANS changer mobile_device_id.
        |
        */

        if (
            $notificationRow->target_type
                === 'all_devices'
            &&
            $device
        ) {

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
                ->whereNull(
                    'customer_account_id'
                )
                ->update([
                    'customer_account_id' =>
                        $customerAccount->id,

                    'updated_at' =>
                        now(),
                ]);
        }


        /*
        |--------------------------------------------------------------------------
        | ALREADY VIEWED
        |--------------------------------------------------------------------------
        */

        $unviewedExists =
            (clone $recipientQuery)
                ->whereNull(
                    'viewed_at'
                )
                ->exists();


        if (!$unviewedExists) {

            return response()->json([
                'success' => true,

                'viewed_at' =>
                    $existingRecipient
                        ->viewed_at,
            ]);
        }


        /*
        |--------------------------------------------------------------------------
        | MARK VIEWED
        |--------------------------------------------------------------------------
        */

        $viewedAt =
            now();


        $recipientQuery
            ->whereNull(
                'viewed_at'
            )
            ->update([
                'viewed_at' =>
                    $viewedAt,

                'updated_at' =>
                    $viewedAt,
            ]);


        return response()->json([
            'success' => true,

            'viewed_at' =>
                $viewedAt
                    ->toISOString(),
        ]);


    } catch (
        Throwable $exception
    ) {

        report(
            $exception
        );


        Log::error(
            'Mobile notification mark viewed error.',
            [
                'notification_id' =>
                    $notification,

                'message' =>
                    $exception
                        ->getMessage(),

                'file' =>
                    $exception
                        ->getFile(),

                'line' =>
                    $exception
                        ->getLine(),
            ]
        );


        return response()->json([
            'success' => false,

            'message' =>
                'Unable to mark notification as viewed.',
        ], 500);
    }
}


    /*
    |--------------------------------------------------------------------------
    | MOBILE: DISMISS NOTIFICATION
    |--------------------------------------------------------------------------
    |
    | POST /api/mobile/notifications/{notification}/dismiss
    |
    | Le recipient n'est PAS supprimé.
    |
    | On conserve :
    | - la livraison
    | - viewed_at
    | - push_sent_at
    | - les statistiques admin
    |
    | On renseigne seulement dismissed_at.
    |
    */

public function dismiss(
    Request $request,
    int $notification
): JsonResponse {
    try {

        /*
        |--------------------------------------------------------------------------
        | CUSTOMER
        |--------------------------------------------------------------------------
        */

        $customerAccount =
            $this->resolveCustomerAccount(
                $request
            );


        /*
        |--------------------------------------------------------------------------
        | DEVICE
        |--------------------------------------------------------------------------
        */

        $deviceId = trim(
            (string) $request->input(
                'device_id',
                ''
            )
        );

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
        | FALLBACK CUSTOMER FROM DEVICE
        |--------------------------------------------------------------------------
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
        | CHECK NOTIFICATION
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
        | CUSTOMER CONNECTED
        |--------------------------------------------------------------------------
        |
        | Si on connaît le customer :
        |
        | supprimer la notification pour :
        |
        | - toutes les lignes ayant customer_account_id = customer
        | - toutes les lignes liées aux devices de ce customer
        |
        */

        if ($customerAccount) {

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
                    ->all();


            $dismissedAt =
                now();


            $updatedRows =
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

                            $query
                                ->where(
                                    'customer_account_id',
                                    $customerAccount->id
                                );


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
                    )
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
                        ->toISOString(),

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
        | ANONYMOUS DEVICE
        |--------------------------------------------------------------------------
        */

        if (!$device) {

            return response()->json([
                'success' => false,
                'message' =>
                    'Device or customer not found.',
            ], 404);
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


        if ($recipient->dismissed_at) {

            return response()->json([
                'success' => true,

                'message' =>
                    'Notification already removed.',

                'dismissed_at' =>
                    $recipient->dismissed_at,
            ]);
        }


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
                    ->toISOString(),

            'updated_recipients' =>
                1,
        ]);


    } catch (
        Throwable $exception
    ) {

        report(
            $exception
        );


        Log::error(
            'Mobile notification dismiss error.',
            [
                'notification_id' =>
                    $notification,

                'message' =>
                    $exception
                        ->getMessage(),

                'file' =>
                    $exception
                        ->getFile(),

                'line' =>
                    $exception
                        ->getLine(),
            ]
        );


        return response()->json([
            'success' => false,

            'message' =>
                'Unable to remove notification.',
        ], 500);
    }
}


    /*
    |--------------------------------------------------------------------------
    | ADMIN/API: SEND NOTIFICATION
    |--------------------------------------------------------------------------
    |
    | POST /api/notifications/{notification}/send
    |
    */

    public function send(
        Request $request,
        int $notification
    ): JsonResponse {
        $notificationRow =
            DB::table(
                'mobile_notifications'
            )
                ->where(
                    'id',
                    $notification
                )
                ->first();

        /*
         * Notification inexistante.
         */

        if (!$notificationRow) {
            return response()->json([
                'success' => false,

                'message' =>
                    'Notification not found.',
            ], 404);
        }

        /*
         * Empêche deux envois simultanés.
         */

        if (
            $notificationRow->status
            === 'sending'
        ) {
            return response()->json([
                'success' => false,

                'message' =>
                    'Notification is already being sent.',
            ], 409);
        }

        /*
         * Lock logique.
         */

        DB::table(
            'mobile_notifications'
        )
            ->where(
                'id',
                $notification
            )
            ->update([
                'status' =>
                    'sending',

                'updated_at' =>
                    now(),
            ]);

        try {
            /*
            |--------------------------------------------------------------------------
            | Build recipients
            |--------------------------------------------------------------------------
            */

            $this->createRecipientsForNotification(
                $notificationRow
            );

            /*
            |--------------------------------------------------------------------------
            | Pending recipient devices
            |--------------------------------------------------------------------------
            */

            $recipients =
                DB::table(
                    'mobile_notification_recipients as r'
                )
                    ->join(
                        'mobile_devices as d',
                        'd.id',
                        '=',
                        'r.mobile_device_id'
                    )
                    ->where(
                        'r.notification_id',
                        $notification
                    )
                    ->where(
                        'r.push_status',
                        'pending'
                    )

                    /*
                     * Une notification déjà dismissed
                     * ne doit jamais être renvoyée.
                     */
                    ->whereNull(
                        'r.dismissed_at'
                    )

                    ->where(
                        'd.is_active',
                        1
                    )
                    ->whereNotNull(
                        'd.expo_push_token'
                    )
                    ->where(
                        'd.expo_push_token',
                        '<>',
                        ''
                    )
                    ->select([
                        'r.id as recipient_id',

                        'd.id as device_id',

                        'd.expo_push_token',

                        'd.locale',

                        'd.platform',

                        'd.customer_account_id',
                    ])
                    ->get();

            /*
            |--------------------------------------------------------------------------
            | Nothing to send
            |--------------------------------------------------------------------------
            */

            if (
                $recipients->isEmpty()
            ) {
                DB::table(
                    'mobile_notifications'
                )
                    ->where(
                        'id',
                        $notification
                    )
                    ->update([
                        'status' =>
                            'sent',

                        'sent_at' =>
                            now(),

                        'updated_at' =>
                            now(),
                    ]);

                return response()->json([
                    'success' => true,

                    'message' =>
                        'Notification processed. No active push devices found.',

                    'total' => 0,

                    'sent' => 0,

                    'failed' => 0,
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Expo batches
            |--------------------------------------------------------------------------
            |
            | Expo accepte les envois groupés.
            | On utilise des chunks de 100.
            |
            */

            $totalSent = 0;

            $totalFailed = 0;

            foreach (
                $recipients->chunk(100)
                as $chunk
            ) {
                $messages = [];

                $recipientMap = [];

                foreach (
                    $chunk
                    as $recipient
                ) {
                    /*
                     * Locale du device.
                     */

                    $isArabic =
                        $recipient->locale
                        === 'ar';

                    /*
                     * Title.
                     */

                    $title =
                        $isArabic
                            ? (
                                $notificationRow
                                    ->title_ar
                                ?:
                                $notificationRow
                                    ->title_en
                            )
                            : (
                                $notificationRow
                                    ->title_en
                                ?:
                                $notificationRow
                                    ->title_ar
                            );

                    /*
                     * Body.
                     */

                    $body =
                        $isArabic
                            ? (
                                $notificationRow
                                    ->body_ar
                                ?:
                                $notificationRow
                                    ->body_en
                            )
                            : (
                                $notificationRow
                                    ->body_en
                                ?:
                                $notificationRow
                                    ->body_ar
                            );

                    /*
                     * Expo message.
                     */

                    $messages[] = [
                        'to' =>
                            $recipient
                                ->expo_push_token,

                        'sound' =>
                            'default',

                        'title' =>
                            $title,

                        'body' =>
                            $body,

                        'data' => [
                            'notification_id' =>
                                $notificationRow
                                    ->id,

                            'screen' =>
                                'notifications',
                        ],

                        'channelId' =>
                            'mchat-high-priority',
                    ];

                    /*
                     * Même ordre que messages.
                     */

                    $recipientMap[] =
                        $recipient;
                }

                /*
                |--------------------------------------------------------------------------
                | Expo request
                |--------------------------------------------------------------------------
                */

                $response =
                    Http::timeout(20)
                        ->acceptJson()
                        ->post(
                            'https://exp.host/--/api/v2/push/send',
                            $messages
                        );

                /*
                 * Requête Expo elle-même
                 * en erreur.
                 */

                if (
                    !$response->successful()
                ) {
                    foreach (
                        $recipientMap
                        as $recipient
                    ) {
                        DB::table(
                            'mobile_notification_recipients'
                        )
                            ->where(
                                'id',
                                $recipient
                                    ->recipient_id
                            )
                            ->update([
                                'push_status' =>
                                    'failed',

                                'updated_at' =>
                                    now(),
                            ]);

                        $totalFailed++;
                    }

                    Log::error(
                        'Expo push request failed.',
                        [
                            'notification_id' =>
                                $notification,

                            'status' =>
                                $response->status(),

                            'body' =>
                                $response->body(),
                        ]
                    );

                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | Expo tickets
                |--------------------------------------------------------------------------
                */

                $responseData =
                    $response->json();

                $tickets =
                    $responseData['data']
                    ?? [];

                foreach (
                    $recipientMap
                    as $index => $recipient
                ) {
                    $ticket =
                        $tickets[$index]
                        ?? null;

                    $success =
                        is_array(
                            $ticket
                        )
                        &&
                        (
                            $ticket['status']
                            ?? null
                        ) === 'ok';

                    /*
                     * Push accepté par Expo.
                     */

                    if ($success) {
                        DB::table(
                            'mobile_notification_recipients'
                        )
                            ->where(
                                'id',
                                $recipient
                                    ->recipient_id
                            )
                            ->update([
                                'push_status' =>
                                    'sent',

                                'push_sent_at' =>
                                    now(),

                                'updated_at' =>
                                    now(),
                            ]);

                        $totalSent++;

                        continue;
                    }

                    /*
                     * Ticket Expo failed.
                     */

                    DB::table(
                        'mobile_notification_recipients'
                    )
                        ->where(
                            'id',
                            $recipient
                                ->recipient_id
                        )
                        ->update([
                            'push_status' =>
                                'failed',

                            'updated_at' =>
                                now(),
                        ]);

                    $totalFailed++;

                    /*
                    |--------------------------------------------------------------------------
                    | Invalid Expo device token
                    |--------------------------------------------------------------------------
                    */

                    $errorCode =
                        is_array($ticket)
                            ? (
                                $ticket['details']['error']
                                ?? null
                            )
                            : null;

                    if (
                        $errorCode ===
                        'DeviceNotRegistered'
                    ) {
                        DB::table(
                            'mobile_devices'
                        )
                            ->where(
                                'id',
                                $recipient
                                    ->device_id
                            )
                            ->update([
                                'is_active' =>
                                    0,

                                'updated_at' =>
                                    now(),
                            ]);
                    }
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Final notification status
            |--------------------------------------------------------------------------
            */

            DB::table(
                'mobile_notifications'
            )
                ->where(
                    'id',
                    $notification
                )
                ->update([
                    'status' =>
                        'sent',

                    'sent_at' =>
                        now(),

                    'updated_at' =>
                        now(),
                ]);

            return response()->json([
                'success' => true,

                'notification_id' =>
                    $notification,

                'total' =>
                    $recipients->count(),

                'sent' =>
                    $totalSent,

                'failed' =>
                    $totalFailed,
            ]);

        } catch (
            Throwable $exception
        ) {
            /*
             * Important :
             *
             * si une erreur serveur intervient,
             * on ne laisse pas status = sending.
             */

            DB::table(
                'mobile_notifications'
            )
                ->where(
                    'id',
                    $notification
                )
                ->update([
                    'status' =>
                        'failed',

                    'updated_at' =>
                        now(),
                ]);

            Log::error(
                'Mobile notification send error.',
                [
                    'notification_id' =>
                        $notification,

                    'message' =>
                        $exception->getMessage(),

                    'file' =>
                        $exception->getFile(),

                    'line' =>
                        $exception->getLine(),

                    'trace' =>
                        $exception
                            ->getTraceAsString(),
                ]
            );

            return response()->json([
                'success' => false,

                'message' =>
                    $exception->getMessage(),

                /*
                 * TEMPORAIRE pour debug.
                 * À retirer en production plus tard.
                 */

                'debug' => [
                    'file' =>
                        $exception->getFile(),

                    'line' =>
                        $exception->getLine(),
                ],
            ], 500);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | CREATE RECIPIENTS
    |--------------------------------------------------------------------------
    */

    private function createRecipientsForNotification(
        object $notification
    ): void {
        /*
        |--------------------------------------------------------------------------
        | SELECTED CUSTOMERS
        |--------------------------------------------------------------------------
        |
        | Les recipients customers ont normalement
        | été créés lors de la création de la notification.
        |
        | On ajoute ici leurs devices actifs.
        |
        */

        if (
            $notification->target_type
            === 'selected_customers'
        ) {
            $customerIds =
                DB::table(
                    'mobile_notification_recipients'
                )
                    ->where(
                        'notification_id',
                        $notification->id
                    )
                    ->whereNotNull(
                        'customer_account_id'
                    )
                    ->pluck(
                        'customer_account_id'
                    )
                    ->unique()
                    ->values();

            foreach (
                $customerIds
                as $customerId
            ) {
                $devices =
                    DB::table(
                        'mobile_devices'
                    )
                        ->where(
                            'customer_account_id',
                            $customerId
                        )
                        ->where(
                            'is_active',
                            1
                        )
                        ->get();

                /*
                 * Aucun device :
                 *
                 * on garde la ligne customer
                 * pending déjà présente.
                 */

                if (
                    $devices->isEmpty()
                ) {
                    continue;
                }

                foreach (
                    $devices
                    as $device
                ) {
                    DB::table(
                        'mobile_notification_recipients'
                    )
                        ->updateOrInsert(
                            [
                                'notification_id' =>
                                    $notification->id,

                                'mobile_device_id' =>
                                    $device->id,
                            ],
                            [
                                'customer_account_id' =>
                                    $customerId,

                                'push_status' =>
                                    'pending',

                                /*
                                 * Nouvelle tentative :
                                 * recipient actif.
                                 */

                                'dismissed_at' =>
                                    null,

                                'updated_at' =>
                                    now(),

                                'created_at' =>
                                    now(),
                            ]
                        );
                }
            }

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | ALL DEVICES
        |--------------------------------------------------------------------------
        */

        if (
            $notification->target_type
            === 'all_devices'
        ) {
            $devices =
                DB::table(
                    'mobile_devices'
                )
                    ->where(
                        'is_active',
                        1
                    )
                    ->get();

            $this->insertDevicesAsRecipients(
                $notification->id,
                $devices
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | ALL CUSTOMERS
        |--------------------------------------------------------------------------
        |
        | Chaque customer possède au minimum
        | une ligne recipient.
        |
        | Customer connecté :
        | mobile_device_id = device actuel
        |
        | Customer sans device :
        | mobile_device_id = NULL
        | push_status = pending
        |
        */

        if (
            $notification->target_type
            === 'all_customers'
        ) {
            $customers =
                CustomerAccount::query()
                    ->select(
                        'id'
                    )
                    ->get();

            foreach (
                $customers
                as $customer
            ) {
                /*
                 * Device actif le plus récent.
                 */

                $device =
                    DB::table(
                        'mobile_devices'
                    )
                        ->where(
                            'customer_account_id',
                            $customer->id
                        )
                        ->where(
                            'is_active',
                            1
                        )
                        ->orderByDesc(
                            'last_seen_at'
                        )
                        ->first();

                /*
                 * Un recipient customer existe
                 * peut-être déjà.
                 */

                $existingRecipient =
                    DB::table(
                        'mobile_notification_recipients'
                    )
                        ->where(
                            'notification_id',
                            $notification->id
                        )
                        ->where(
                            'customer_account_id',
                            $customer->id
                        )
                        ->first();

                if (
                    $existingRecipient
                ) {
                    /*
                     * S'il était sans device
                     * mais qu'un device existe maintenant,
                     * on peut l'associer.
                     */

                    if (
                        !$existingRecipient
                            ->mobile_device_id
                        &&
                        $device
                    ) {
                        DB::table(
                            'mobile_notification_recipients'
                        )
                            ->where(
                                'id',
                                $existingRecipient->id
                            )
                            ->update([
                                'mobile_device_id' =>
                                    $device->id,

                                'updated_at' =>
                                    now(),
                            ]);
                    }

                    continue;
                }

                DB::table(
                    'mobile_notification_recipients'
                )
                    ->insert([
                        'notification_id' =>
                            $notification->id,

                        'customer_account_id' =>
                            $customer->id,

                        'mobile_device_id' =>
                            $device
                                ? $device->id
                                : null,

                        'push_status' =>
                            'pending',

                        'push_sent_at' =>
                            null,

                        'viewed_at' =>
                            null,

                        'dismissed_at' =>
                            null,

                        'created_at' =>
                            now(),

                        'updated_at' =>
                            now(),
                    ]);
            }

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | ANONYMOUS DEVICES
        |--------------------------------------------------------------------------
        */

        if (
            $notification->target_type
            === 'anonymous_devices'
        ) {
            $devices =
                DB::table(
                    'mobile_devices'
                )
                    ->where(
                        'is_active',
                        1
                    )
                    ->whereNull(
                        'customer_account_id'
                    )
                    ->get();

            $this->insertDevicesAsRecipients(
                $notification->id,
                $devices
            );

            return;
        }

        throw new \RuntimeException(
            'Unsupported notification target type.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | INSERT DEVICE RECIPIENTS
    |--------------------------------------------------------------------------
    */

    private function insertDevicesAsRecipients(
        int $notificationId,
        $devices
    ): void {
        foreach (
            $devices
            as $device
        ) {
            DB::table(
                'mobile_notification_recipients'
            )
                ->updateOrInsert(
                    [
                        'notification_id' =>
                            $notificationId,

                        'mobile_device_id' =>
                            $device->id,
                    ],
                    [
                        'customer_account_id' =>
                            $device
                                ->customer_account_id,

                        'push_status' =>
                            'pending',

                        'dismissed_at' =>
                            null,

                        'updated_at' =>
                            now(),

                        'created_at' =>
                            now(),
                    ]
                );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | SEND PENDING FOR CUSTOMER DEVICE
    |--------------------------------------------------------------------------
    |
    | Lorsqu'un customer se reconnecte :
    |
    | - on récupère ses notifications pending
    | - on associe le nouveau device
    | - on les envoie
    |
    */

    public function sendPendingForCustomerDevice(
        int $customerAccountId,
        int $deviceId
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Verify device
        |--------------------------------------------------------------------------
        */

        $device =
            DB::table(
                'mobile_devices'
            )
                ->where(
                    'id',
                    $deviceId
                )
                ->where(
                    'customer_account_id',
                    $customerAccountId
                )
                ->where(
                    'is_active',
                    1
                )
                ->whereNotNull(
                    'expo_push_token'
                )
                ->where(
                    'expo_push_token',
                    '<>',
                    ''
                )
                ->first();

        if (!$device) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Pending notifications
        |--------------------------------------------------------------------------
        */

        $pendingRecipients =
            DB::table(
                'mobile_notification_recipients as r'
            )
                ->join(
                    'mobile_notifications as n',
                    'n.id',
                    '=',
                    'r.notification_id'
                )
                ->where(
                    'r.customer_account_id',
                    $customerAccountId
                )
                ->where(
                    'r.push_status',
                    'pending'
                )

                /*
                 * Notification supprimée :
                 * ne jamais la renvoyer.
                 */
                ->whereNull(
                    'r.dismissed_at'
                )

                ->where(
                    'n.status',
                    'sent'
                )
                ->select([
                    'r.id as recipient_id',

                    'r.notification_id',

                    'n.title_en',
                    'n.title_ar',
                    'n.body_en',
                    'n.body_ar',
                ])
                ->orderBy(
                    'n.created_at'
                )
                ->get();

        foreach (
            $pendingRecipients
            as $recipient
        ) {
            /*
            |--------------------------------------------------------------------------
            | Associate device
            |--------------------------------------------------------------------------
            */

            DB::table(
                'mobile_notification_recipients'
            )
                ->where(
                    'id',
                    $recipient->recipient_id
                )
                ->update([
                    'mobile_device_id' =>
                        $device->id,

                    'updated_at' =>
                        now(),
                ]);

            /*
            |--------------------------------------------------------------------------
            | Locale
            |--------------------------------------------------------------------------
            */

            $isArabic =
                $device->locale
                === 'ar';

            $title =
                $isArabic
                    ? (
                        $recipient->title_ar
                        ?: $recipient->title_en
                    )
                    : (
                        $recipient->title_en
                        ?: $recipient->title_ar
                    );

            $body =
                $isArabic
                    ? (
                        $recipient->body_ar
                        ?: $recipient->body_en
                    )
                    : (
                        $recipient->body_en
                        ?: $recipient->body_ar
                    );

            /*
            |--------------------------------------------------------------------------
            | Expo
            |--------------------------------------------------------------------------
            */

            try {
                $response =
                    Http::timeout(20)
                        ->acceptJson()
                        ->post(
                            'https://exp.host/--/api/v2/push/send',
                            [
                                'to' =>
                                    $device
                                        ->expo_push_token,

                                'sound' =>
                                    'default',

                                'title' =>
                                    $title,

                                'body' =>
                                    $body,

                                'data' => [
                                    'notification_id' =>
                                        $recipient
                                            ->notification_id,

                                    'screen' =>
                                        'notifications',
                                ],

                                'channelId' =>
                                    'mchat-high-priority',
                            ]
                        );

                $responseData =
                    $response->json();

                /*
                 * Pour un message unique,
                 * Expo renvoie généralement
                 * directement un ticket dans data.
                 */

                $ticket =
                    $responseData['data']
                    ?? null;

                $success =
                    $response->successful()
                    &&
                    is_array(
                        $ticket
                    )
                    &&
                    (
                        $ticket['status']
                        ?? null
                    ) === 'ok';

                if ($success) {
                    DB::table(
                        'mobile_notification_recipients'
                    )
                        ->where(
                            'id',
                            $recipient
                                ->recipient_id
                        )
                        ->update([
                            'push_status' =>
                                'sent',

                            'push_sent_at' =>
                                now(),

                            'updated_at' =>
                                now(),
                        ]);

                    continue;
                }

                /*
                 * On garde pending pour
                 * pouvoir réessayer plus tard.
                 */

                Log::warning(
                    'Pending notification push failed.',
                    [
                        'notification_id' =>
                            $recipient
                                ->notification_id,

                        'customer_account_id' =>
                            $customerAccountId,

                        'device_id' =>
                            $deviceId,

                        'response' =>
                            $responseData,
                    ]
                );

                /*
                 * Si le token est définitivement
                 * invalide, désactiver le device.
                 */

                $errorCode =
                    is_array($ticket)
                        ? (
                            $ticket['details']['error']
                            ?? null
                        )
                        : null;

                if (
                    $errorCode ===
                    'DeviceNotRegistered'
                ) {
                    DB::table(
                        'mobile_devices'
                    )
                        ->where(
                            'id',
                            $deviceId
                        )
                        ->update([
                            'is_active' =>
                                0,

                            'updated_at' =>
                                now(),
                        ]);
                }

            } catch (
                Throwable $exception
            ) {
                Log::error(
                    'Pending notification send error.',
                    [
                        'notification_id' =>
                            $recipient
                                ->notification_id,

                        'customer_account_id' =>
                            $customerAccountId,

                        'device_id' =>
                            $deviceId,

                        'message' =>
                            $exception
                                ->getMessage(),
                    ]
                );
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | RESOLVE CUSTOMER ACCOUNT
    |--------------------------------------------------------------------------
    |
    | access_token clair
    | ↓
    | SHA-256
    | ↓
    | access_token_hash en DB
    |
    */

    private function resolveCustomerAccount(
        Request $request
    ): ?CustomerAccount {
        $accessToken =
            trim(
                (string)
                $request->input(
                    'access_token',
                    ''
                )
            );

        if (
            $accessToken === ''
        ) {
            return null;
        }

        $tokenHash =
            hash(
                'sha256',
                $accessToken
            );

        return CustomerAccount::query()
            ->where(
                'access_token_hash',
                $tokenHash
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
    | RESOLVE LOCALE
    |--------------------------------------------------------------------------
    */

    private function resolveLocale(
        Request $request,
        $device = null
    ): string {
        $locale =
            trim(
                (string)
                $request->input(
                    'locale',
                    ''
                )
            );

        /*
         * Locale explicitement envoyé
         * par l'application.
         */

        if (
            in_array(
                $locale,
                [
                    'ar',
                    'en',
                ],
                true
            )
        ) {
            return $locale;
        }

        /*
         * Sinon locale enregistrée
         * sur le device.
         */

        if (
            $device
            &&
            in_array(
                $device->locale,
                [
                    'ar',
                    'en',
                ],
                true
            )
        ) {
            return $device->locale;
        }

        return 'en';
    }
}