<?php

namespace App\Http\Controllers;

use App\Models\CustomerAccount;
use App\Models\MobileDevice;
use App\Models\MobileNotification;
use App\Models\MobileNotificationRecipient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AppNotificationController extends Controller
{
    public function index()
    {
        $notifications =
            MobileNotification::query()
                ->withCount([
                    'recipients',

                    'recipients as sent_count' =>
                        function ($query) {
                            $query->where(
                                'push_status',
                                'sent'
                            );
                        },

                    'recipients as pending_count' =>
                        function ($query) {
                            $query->where(
                                'push_status',
                                'pending'
                            );
                        },

                    'recipients as failed_count' =>
                        function ($query) {
                            $query->where(
                                'push_status',
                                'failed'
                            );
                        },

                    'recipients as viewed_count' =>
                        function ($query) {
                            $query->whereNotNull(
                                'viewed_at'
                            );
                        },
                ])
                ->orderByDesc('id')
                ->paginate(20);

        $notifications
            ->getCollection()
            ->transform(
                function ($notification) {

                    $notification->view_percentage =
                        $notification->sent_count > 0
                            ? round(
                                (
                                    $notification->viewed_count
                                    /
                                    $notification->sent_count
                                ) * 100,
                                1
                            )
                            : 0;

                    return $notification;
                }
            );

        $summary = [
            'total' =>
                MobileNotification::count(),

            'sent' =>
                MobileNotification::where(
                    'status',
                    'sent'
                )->count(),

            'draft' =>
                MobileNotification::where(
                    'status',
                    'draft'
                )->count(),

            'sending' =>
                MobileNotification::where(
                    'status',
                    'sending'
                )->count(),

            'failed' =>
                MobileNotification::where(
                    'status',
                    'failed'
                )->count(),
        ];

        return view(
            'app-communications.notifications.index',
            compact(
                'notifications',
                'summary'
            )
        );
    }


    public function show(
        MobileNotification $notification
    ) {
        $notification->load([
            'recipients.customerAccount',
            'recipients.mobileDevice',
        ]);

        $totalRecipients =
            $notification->recipients->count();

        $sentCount =
            $notification->recipients
                ->where(
                    'push_status',
                    'sent'
                )
                ->count();

        $pendingCount =
            $notification->recipients
                ->where(
                    'push_status',
                    'pending'
                )
                ->count();

        $failedCount =
            $notification->recipients
                ->where(
                    'push_status',
                    'failed'
                )
                ->count();

        $viewedCount =
            $notification->recipients
                ->whereNotNull('viewed_at')
                ->count();

        $viewPercentage =
            $sentCount > 0
                ? round(
                    ($viewedCount / $sentCount) * 100,
                    1
                )
                : 0;

        $recipients =
            $notification->recipients
                ->map(
                    function ($recipient) {

                        $customer =
                            $recipient->customerAccount;

                        $device =
                            $recipient->mobileDevice;

                        return [
                            'id' =>
                                $recipient->id,

                            'customer_id' =>
                                $customer?->id,

                            'phone' =>
                                $customer?->phone
                                ?? '—',

                            'name' =>
                                $customer
                                    ? 'Customer #'
                                        . $customer->id
                                    : 'Anonymous device',

                            'device_name' =>
                                $device?->device_name
                                ?? '—',

                            'platform' =>
                                $device?->platform
                                ?? '—',

                            'locale' =>
                                $device?->locale
                                ?? '—',

                            'push_status' =>
                                $recipient->push_status,

                            'push_sent_at' =>
                                $recipient->push_sent_at,

                            'viewed' =>
                                !is_null(
                                    $recipient->viewed_at
                                ),

                            'viewed_at' =>
                                $recipient->viewed_at,
                        ];
                    }
                );

        $stats = [
            'total_recipients' =>
                $totalRecipients,

            'sent_count' =>
                $sentCount,

            'pending_count' =>
                $pendingCount,

            'failed_count' =>
                $failedCount,

            'viewed_count' =>
                $viewedCount,

            'view_percentage' =>
                $viewPercentage,
        ];

        return view(
            'app-communications.notifications.show',
            compact(
                'notification',
                'recipients',
                'stats'
            )
        );
    }


    /*
    |--------------------------------------------------------------------------
    | CREATE
    |--------------------------------------------------------------------------
    */

    public function create()
    {
        $customers =
            CustomerAccount::query()
                ->orderBy('phone')
                ->get([
                    'id',
                    'phone',
                ]);

        return view(
            'app-communications.notifications.create',
            compact('customers')
        );
    }


    /*
    |--------------------------------------------------------------------------
    | STORE
    |--------------------------------------------------------------------------
    */

    public function store(
        Request $request
    ) {
        $validated =
            $request->validate([
                'title_en' => [
                    'required',
                    'string',
                    'max:255',
                ],

                'title_ar' => [
                    'required',
                    'string',
                    'max:255',
                ],

                'body_en' => [
                    'required',
                    'string',
                    'max:2000',
                ],

                'body_ar' => [
                    'required',
                    'string',
                    'max:2000',
                ],

                'target_type' => [
                    'required',
                    'in:all_devices,all_customers,anonymous_devices,selected_customers',
                ],

                'customers' => [
                    'nullable',
                    'array',
                ],

                'customers.*' => [
                    'integer',
                    'exists:customer_accounts,id',
                ],

                'action' => [
                    'required',
                    'in:draft,send',
                ],
            ]);

        if (
            $validated['target_type'] ===
                'selected_customers'
            &&
            empty(
                $validated['customers']
            )
        ) {
            return back()
                ->withInput()
                ->withErrors([
                    'customers' =>
                        'Please select at least one customer.',
                ]);
        }

        $notification =
            MobileNotification::create([
                'title_en' =>
                    $validated['title_en'],

                'title_ar' =>
                    $validated['title_ar'],

                'body_en' =>
                    $validated['body_en'],

                'body_ar' =>
                    $validated['body_ar'],

                'target_type' =>
                    $validated['target_type'],

                'status' =>
                    'draft',
            ]);

        /*
         * Pour selected_customers,
         * on crée immédiatement les recipients.
         */
        if (
            $validated['target_type'] ===
            'selected_customers'
        ) {
            $this->createSelectedCustomerRecipients(
                $notification,
                $validated['customers']
            );
        }

        if (
            $validated['action'] ===
            'send'
        ) {
            return $this->send(
                $notification
            );
        }

        return redirect()
            ->route(
                'app-communications.notifications.show',
                $notification
            )
            ->with(
                'success',
                'Notification saved as draft.'
            );
    }


    /*
    |--------------------------------------------------------------------------
    | SEND
    |--------------------------------------------------------------------------
    */

    public function send(
        MobileNotification $notification
    ) {
        if (
            $notification->status ===
            'sending'
        ) {
            return back()
                ->with(
                    'error',
                    'Notification is already being sent.'
                );
        }

        DB::beginTransaction();

        try {

            $notification->update([
                'status' =>
                    'sending',
            ]);

            /*
             * Créer les recipients selon la cible.
             */
            $this->ensureRecipientsExist(
                $notification
            );

            $recipients =
                MobileNotificationRecipient::query()
                    ->with([
                        'mobileDevice',
                        'customerAccount',
                    ])
                    ->where(
                        'notification_id',
                        $notification->id
                    )
                    ->where(
                        'push_status',
                        'pending'
                    )
                    ->get();

            DB::commit();

        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error(
                'Admin notification preparation error',
                [
                    'notification_id' =>
                        $notification->id,

                    'message' =>
                        $e->getMessage(),
                ]
            );

            $notification->update([
                'status' =>
                    'failed',
            ]);

            return back()
                ->with(
                    'error',
                    'Unable to prepare notification.'
                );
        }

        $sent = 0;
        $failed = 0;
        $pending = 0;

        foreach (
            $recipients
            as $recipient
        ) {
            $device =
                $recipient->mobileDevice;

            /*
             * Customer sans device connecté :
             * on garde pending.
             */
            if (
                !$device
                ||
                !$device->is_active
                ||
                empty(
                    $device->expo_push_token
                )
            ) {
                $pending++;

                continue;
            }

            $isArabic =
                $device->locale === 'ar';

            $title =
                $isArabic
                    ? (
                        $notification->title_ar
                        ?: $notification->title_en
                    )
                    : (
                        $notification->title_en
                        ?: $notification->title_ar
                    );

            $body =
                $isArabic
                    ? (
                        $notification->body_ar
                        ?: $notification->body_en
                    )
                    : (
                        $notification->body_en
                        ?: $notification->body_ar
                    );

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
                                        $notification->id,

                                    'screen' =>
                                        'notifications',
                                ],

                                'channelId' =>
                                    'mchat-high-priority',
                            ]
                        );

                $data =
                    $response->json();

                $ticket =
                    $data['data']
                    ?? null;

                $success =
                    $response->successful()
                    &&
                    is_array($ticket)
                    &&
                    (
                        $ticket['status']
                        ?? null
                    ) === 'ok';

                if ($success) {

                    $recipient->update([
                        'push_status' =>
                            'sent',

                        'push_sent_at' =>
                            now(),
                    ]);

                    $sent++;

                } else {

                    $recipient->update([
                        'push_status' =>
                            'failed',
                    ]);

                    $failed++;
                }

            } catch (\Throwable $e) {

                Log::error(
                    'Admin notification push error',
                    [
                        'notification_id' =>
                            $notification->id,

                        'recipient_id' =>
                            $recipient->id,

                        'message' =>
                            $e->getMessage(),
                    ]
                );

                $recipient->update([
                    'push_status' =>
                        'failed',
                ]);

                $failed++;
            }
        }

        /*
         * La campagne est considérée envoyée,
         * même si certains recipients restent pending.
         */
        $notification->update([
            'status' =>
                'sent',

            'sent_at' =>
                now(),
        ]);

        return redirect()
            ->route(
                'app-communications.notifications.show',
                $notification
            )
            ->with(
                'success',
                "Notification processed. Sent: {$sent}, Pending: {$pending}, Failed: {$failed}."
            );
    }


    /*
    |--------------------------------------------------------------------------
    | RECIPIENT CREATION
    |--------------------------------------------------------------------------
    */

    private function ensureRecipientsExist(
        MobileNotification $notification
    ): void {

        if (
            $notification->target_type ===
            'selected_customers'
        ) {
            return;
        }

        if (
            $notification->target_type ===
            'all_customers'
        ) {
            $customers =
                CustomerAccount::query()
                    ->select('id')
                    ->get();

            foreach (
                $customers
                as $customer
            ) {
                $device =
                    MobileDevice::query()
                        ->where(
                            'customer_account_id',
                            $customer->id
                        )
                        ->where(
                            'is_active',
                            true
                        )
                        ->orderByDesc(
                            'last_seen_at'
                        )
                        ->first();

                MobileNotificationRecipient
                    ::firstOrCreate(
                        [
                            'notification_id' =>
                                $notification->id,

                            'customer_account_id' =>
                                $customer->id,
                        ],
                        [
                            'mobile_device_id' =>
                                $device?->id,

                            'push_status' =>
                                'pending',
                        ]
                    );
            }

            return;
        }

        if (
            $notification->target_type ===
            'anonymous_devices'
        ) {
            $devices =
                MobileDevice::query()
                    ->where(
                        'is_active',
                        true
                    )
                    ->whereNull(
                        'customer_account_id'
                    )
                    ->get();

            foreach (
                $devices
                as $device
            ) {
                MobileNotificationRecipient
                    ::firstOrCreate(
                        [
                            'notification_id' =>
                                $notification->id,

                            'mobile_device_id' =>
                                $device->id,
                        ],
                        [
                            'customer_account_id' =>
                                null,

                            'push_status' =>
                                'pending',
                        ]
                    );
            }

            return;
        }

        /*
         * all_devices
         */
        $devices =
            MobileDevice::query()
                ->where(
                    'is_active',
                    true
                )
                ->get();

        foreach (
            $devices
            as $device
        ) {
            MobileNotificationRecipient
                ::firstOrCreate(
                    [
                        'notification_id' =>
                            $notification->id,

                        'mobile_device_id' =>
                            $device->id,
                    ],
                    [
                        'customer_account_id' =>
                            $device
                                ->customer_account_id,

                        'push_status' =>
                            'pending',
                    ]
                );
        }
    }


    private function createSelectedCustomerRecipients(
        MobileNotification $notification,
        array $customerIds
    ): void {

        foreach (
            $customerIds
            as $customerId
        ) {
            $device =
                MobileDevice::query()
                    ->where(
                        'customer_account_id',
                        $customerId
                    )
                    ->where(
                        'is_active',
                        true
                    )
                    ->orderByDesc(
                        'last_seen_at'
                    )
                    ->first();

            MobileNotificationRecipient::create([
                'notification_id' =>
                    $notification->id,

                'customer_account_id' =>
                    $customerId,

                'mobile_device_id' =>
                    $device?->id,

                'push_status' =>
                    'pending',
            ]);
        }
    }
}