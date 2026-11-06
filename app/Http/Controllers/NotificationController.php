<?php

namespace App\Http\Controllers;

use App\Models\CustomerAccount;
use App\Models\MobileNotification;
use App\Models\MobileNotificationRecipient;
use App\Models\Product;
use App\Services\Notifications\NotificationAudience;
use App\Services\Notifications\NotificationCenter;
use App\Support\AppNotifications\NotificationDestinations;
use App\Support\AppNotifications\NotificationTypes;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Notifications" menu: every notification of the app (automatic and
 * manual), scheduled / sent / cancelled, with what the customer receives,
 * where it opens in the app and whether he read it.
 */
class NotificationController extends Controller
{
    /** User permissions (group "Notifications" in User::permissionGroups) */
    public const PERMISSION_VIEW = 'notifications.view';
    public const PERMISSION_MANAGE = 'notifications.manage';
    public const PERMISSION_SETTINGS = 'notifications.settings';

    public const TABS = [
        'upcoming' => 'Scheduled',
        'sent' => 'Sent',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
        'all' => 'All',
    ];

    public function __construct(private NotificationCenter $center)
    {
    }

    /*
    |--------------------------------------------------------------------------
    | LIST
    |--------------------------------------------------------------------------
    */

    public function index(Request $request)
    {
        $this->authorizePermission(self::PERMISSION_VIEW);

        $filters = [
            'tab' => array_key_exists($request->input('tab'), self::TABS) ? $request->input('tab') : 'upcoming',
            'from' => (string) $request->input('from', ''),
            'to' => (string) $request->input('to', ''),
            'customer' => trim((string) $request->input('customer', '')),
            'customer_id' => (int) $request->input('customer_id', 0),
            'type' => (string) $request->input('type', ''),
            'read' => (string) $request->input('read', ''),
        ];

        $dateSql = 'COALESCE(mobile_notifications.scheduled_at, mobile_notifications.sent_at, mobile_notifications.created_at)';

        $base = MobileNotification::query()
            ->when($filters['from'] !== '', fn ($query) => $query->whereRaw("DATE({$dateSql}) >= ?", [Carbon::parse($filters['from'])->toDateString()]))
            ->when($filters['to'] !== '', fn ($query) => $query->whereRaw("DATE({$dateSql}) <= ?", [Carbon::parse($filters['to'])->toDateString()]))
            ->when($filters['customer_id'] > 0, fn ($query) => $query->whereHas('recipients', fn ($q) => $q->where('customer_account_id', $filters['customer_id'])))
            ->when($filters['customer_id'] === 0 && $filters['customer'] !== '', function ($query) use ($filters) {
                $ids = $this->searchCustomers($filters['customer'], 200)->pluck('id')->all();
                $query->whereHas('recipients', fn ($q) => $q->whereIn('customer_account_id', $ids ?: [0]));
            })
            ->when($filters['type'] !== '', function ($query) use ($filters) {
                match ($filters['type']) {
                    'manual' => $query->where('source', MobileNotification::SOURCE_MANUAL),
                    'other' => $query->whereNull('source'),
                    default => $query->where('auto_type', $filters['type']),
                };
            })
            ->when($filters['read'] === 'yes', fn ($query) => $query->whereHas('recipients', fn ($q) => $this->whereRead($q)))
            ->when($filters['read'] === 'no', fn ($query) => $query->where('status', 'sent')
                ->whereDoesntHave('recipients', fn ($q) => $this->whereRead($q)));

        // Counters of the tabs (same filters)
        $statusCounts = (clone $base)
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $tabCounts = [
            'upcoming' => (int) ($statusCounts['scheduled'] ?? 0),
            'sent' => (int) ($statusCounts['sent'] ?? 0),
            'failed' => (int) ($statusCounts['failed'] ?? 0) + (int) ($statusCounts['sending'] ?? 0),
            'cancelled' => (int) ($statusCounts['cancelled'] ?? 0),
            'all' => array_sum($statusCounts),
        ];

        $notifications = $base
            ->when($filters['tab'] === 'upcoming', fn ($query) => $query->where('status', 'scheduled'))
            ->when($filters['tab'] === 'sent', fn ($query) => $query->where('status', 'sent'))
            ->when($filters['tab'] === 'failed', fn ($query) => $query->whereIn('status', ['failed', 'sending']))
            ->when($filters['tab'] === 'cancelled', fn ($query) => $query->where('status', 'cancelled'))
            ->with(['customerAccount.subscription'])
            ->withCount([
                'recipients',
                'recipients as read_count' => fn ($q) => $this->whereRead($q),
                'recipients as opened_count' => fn ($q) => $q->whereNotNull('opened_at'),
                'recipients as pushed_count' => fn ($q) => $q->where('push_status', 'sent'),
            ])
            ->when(
                $filters['tab'] === 'upcoming',
                fn ($query) => $query->orderBy('scheduled_at')->orderBy('id'),
                fn ($query) => $query->orderByRaw("{$dateSql} DESC")->orderByDesc('id')
            )
            ->paginate(30)
            ->withQueryString();

        // Single recipient of the manual notifications of the page
        $singleRecipients = MobileNotificationRecipient::query()
            ->whereIn('notification_id', collect($notifications->items())->filter(fn ($n) => !$n->customer_account_id && $n->recipients_count === 1)->pluck('id'))
            ->with('customerAccount.subscription')
            ->get()
            ->keyBy('notification_id');

        $selectedCustomer = $filters['customer_id'] > 0 ? CustomerAccount::with('subscription')->find($filters['customer_id']) : null;

        return view('notifications.index', [
            'notifications' => $notifications,
            'filters' => $filters,
            'tabCounts' => $tabCounts,
            'singleRecipients' => $singleRecipients,
            'selectedCustomer' => $selectedCustomer,
            'types' => NotificationTypes::all(),
            'canManage' => $request->user()->hasPermission(self::PERMISSION_MANAGE),
            'installed' => NotificationTypes::installed(),
        ]);
    }

    public function show(Request $request, MobileNotification $notification)
    {
        $this->authorizePermission(self::PERMISSION_VIEW);

        $notification->load(['customerAccount.subscription', 'createdByUser', 'editedByUser', 'cancelledByUser']);

        $recipients = $notification->recipients()
            ->with(['customerAccount.subscription', 'mobileDevice'])
            ->orderByRaw('CASE WHEN opened_at IS NOT NULL THEN 0 WHEN viewed_at IS NOT NULL THEN 1 ELSE 2 END')
            ->orderBy('id')
            ->paginate(100)
            ->withQueryString();

        $stats = $notification->recipients()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(push_status = 'sent') AS pushed")
            ->selectRaw("SUM(push_status = 'no_device') AS no_device")
            ->selectRaw("SUM(push_status = 'failed') AS failed")
            ->selectRaw('SUM(viewed_at IS NOT NULL OR opened_at IS NOT NULL) AS seen')
            ->selectRaw('SUM(opened_at IS NOT NULL) AS opened')
            ->first();

        return view('notifications.show', [
            'notification' => $notification,
            'recipients' => $recipients,
            'stats' => $stats,
            'canManage' => $request->user()->hasPermission(self::PERMISSION_MANAGE),
            'plannedOrderId' => $notification->destination_params['planned_order_id'] ?? null,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | MANUAL NOTIFICATION
    |--------------------------------------------------------------------------
    */

    public function create(Request $request)
    {
        $this->authorizePermission(self::PERMISSION_MANAGE);

        $preselected = collect((array) $request->input('customers', []))
            ->merge($request->filled('customer_id') ? [(int) $request->input('customer_id')] : [])
            ->filter()
            ->unique();

        return view('notifications.form', $this->formData(new MobileNotification([
            'source' => MobileNotification::SOURCE_MANUAL,
            'target_type' => 'selected_customers',
            'destination' => NotificationDestinations::DEFAULT,
        ]), $preselected->all()));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizePermission(self::PERMISSION_MANAGE);

        $data = $this->validated($request, null);

        $notification = DB::transaction(function () use ($data, $request) {
            $notification = MobileNotification::create([
                'type' => MobileNotification::TYPE_GENERAL,
                'source' => MobileNotification::SOURCE_MANUAL,
                'target_type' => $data['target_type'],
                'status' => 'scheduled',
                'scheduled_at' => $data['scheduled_at'],
                'title_en' => $data['title_en'],
                'title_ar' => $data['title_ar'],
                'body_en' => $data['body_en'],
                'body_ar' => $data['body_ar'],
                'destination' => $data['destination'],
                'destination_params' => $data['destination_params'],
                'customer_account_id' => $data['single_customer_id'],
                'created_by' => $request->user()->id,
            ]);

            NotificationAudience::syncRecipients($notification, $data['customer_ids']);

            return $notification;
        });

        return $this->afterSave($notification, $data['send_now'], 'Notification created');
    }

    /*
    |--------------------------------------------------------------------------
    | EDIT (texts, date, destination, customers)
    |--------------------------------------------------------------------------
    */

    public function edit(MobileNotification $notification)
    {
        $this->authorizePermission(self::PERMISSION_MANAGE);

        if (!$notification->isEditable() || !$notification->source) {
            return redirect()->route('notifications.show', $notification)
                ->with('error', 'This notification was already sent: it cannot be changed.');
        }

        $customerIds = $notification->isSelectedCustomers() && $notification->isManual()
            ? $notification->recipients()->pluck('customer_account_id')->filter()->all()
            : [];

        return view('notifications.form', $this->formData($notification, $customerIds));
    }

    public function update(Request $request, MobileNotification $notification): RedirectResponse
    {
        $this->authorizePermission(self::PERMISSION_MANAGE);

        if (!$notification->isEditable() || !$notification->source) {
            return redirect()->route('notifications.show', $notification)
                ->with('error', 'This notification was already sent: it cannot be changed.');
        }

        $data = $this->validated($request, $notification);

        DB::transaction(function () use ($notification, $data, $request) {
            $notification->forceFill([
                'title_en' => $data['title_en'],
                'title_ar' => $data['title_ar'],
                'body_en' => $data['body_en'],
                'body_ar' => $data['body_ar'],
                'destination' => $data['destination'],
                'destination_params' => $data['destination_params'],
                'scheduled_at' => $data['scheduled_at'],
                'status' => 'scheduled',
                'last_error' => null,
                'cancelled_at' => null,
                'cancelled_by' => null,
                'cancel_reason' => null,
                'edited_at' => now(),
                'edited_by' => $request->user()->id,
            ]);

            if ($notification->isManual()) {
                $notification->target_type = $data['target_type'];
                $notification->customer_account_id = $data['single_customer_id'];
            }

            $notification->save();

            if ($notification->isManual()) {
                NotificationAudience::syncRecipients($notification, $data['customer_ids']);
            }
        });

        return $this->afterSave($notification, $data['send_now'], 'Notification updated');
    }

    /*
    |--------------------------------------------------------------------------
    | ACTIONS
    |--------------------------------------------------------------------------
    */

    public function cancel(Request $request, MobileNotification $notification): RedirectResponse
    {
        $this->authorizePermission(self::PERMISSION_MANAGE);

        $cancelled = MobileNotification::whereKey($notification->id)
            ->whereIn('status', ['scheduled', 'failed', 'draft'])
            ->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $request->user()->id,
                'cancel_reason' => mb_substr(trim((string) $request->input('reason', '')) ?: 'Cancelled by ' . $request->user()->name, 0, 250),
                'updated_at' => now(),
            ]);

        return back()->with(
            $cancelled ? 'success' : 'error',
            $cancelled ? 'Notification cancelled: it will not be sent.' : 'This notification cannot be cancelled any more (already sent).'
        );
    }

    /** Cancelled -> scheduled again (same date, or in 5 minutes when passed). */
    public function restore(Request $request, MobileNotification $notification): RedirectResponse
    {
        $this->authorizePermission(self::PERMISSION_MANAGE);

        if (!$notification->isCancelled()) {
            return back()->with('error', 'Only a cancelled notification can be scheduled again.');
        }

        $at = $notification->scheduled_at && $notification->scheduled_at->isFuture()
            ? $notification->scheduled_at
            : now()->addMinutes(5);

        $notification->forceFill([
            'status' => 'scheduled',
            'scheduled_at' => $at,
            'cancelled_at' => null,
            'cancelled_by' => null,
            'cancel_reason' => null,
            'edited_at' => now(),
            'edited_by' => $request->user()->id,
        ])->save();

        return back()->with('success', 'Notification scheduled again for ' . $at->format('d/m/Y H:i') . '.');
    }

    public function sendNow(Request $request, MobileNotification $notification): RedirectResponse
    {
        $this->authorizePermission(self::PERMISSION_MANAGE);

        if (!in_array($notification->status, ['scheduled', 'failed', 'cancelled'], true) || !$notification->source) {
            return back()->with('error', 'This notification cannot be sent now.');
        }

        $notification->forceFill([
            'status' => 'scheduled',
            'scheduled_at' => now(),
            'cancelled_at' => null,
            'cancelled_by' => null,
            'cancel_reason' => null,
        ])->save();

        return $this->afterSave($notification, true, 'Notification');
    }

    /** Removes one customer of a notification not sent yet. */
    public function removeRecipient(MobileNotification $notification, MobileNotificationRecipient $recipient): RedirectResponse
    {
        $this->authorizePermission(self::PERMISSION_MANAGE);

        abort_unless((int) $recipient->notification_id === (int) $notification->id, 404);

        if (!$notification->isEditable() || $recipient->push_status !== 'pending') {
            return back()->with('error', 'Already sent to this customer.');
        }

        if ($notification->isGroupTarget()) {
            return back()->with('error', 'The customers of this notification are chosen when it is sent: change its target instead.');
        }

        $recipient->delete();

        $left = $notification->recipients()->count();

        if ($left === 0) {
            $notification->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
                'cancel_reason' => 'No customer left.',
            ])->save();
        } elseif ($left === 1) {
            $notification->forceFill(['customer_account_id' => $notification->recipients()->value('customer_account_id')])->save();
        }

        return back()->with('success', 'Customer removed from this notification.');
    }

    /** Customers picker (name, phone, id, subscription code). */
    public function customers(Request $request): JsonResponse
    {
        $this->authorizePermission(self::PERMISSION_VIEW);

        $q = trim((string) $request->input('q', ''));

        $customers = $q === ''
            ? NotificationAudience::customers()->with('subscription')->orderByDesc('last_app_activity_at')->limit(20)->get()
            : $this->searchCustomers($q, 20);

        return response()->json($customers->map(fn ($customer) => [
            'id' => $customer->id,
            'label' => NotificationAudience::label($customer),
            'has_phone' => DB::table('mobile_devices')->where('customer_account_id', $customer->id)->where('is_active', 1)->whereNotNull('expo_push_token')->exists(),
        ])->values());
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    private function afterSave(MobileNotification $notification, bool $sendNow, string $message): RedirectResponse
    {
        $redirect = redirect()->route('notifications.show', $notification);

        if (!$sendNow) {
            return $redirect->with('success', "{$message}: scheduled for " . $notification->scheduled_at->format('d/m/Y H:i') . '.');
        }

        $this->center->send($notification);
        $notification->refresh();

        if ($notification->isSent()) {
            $pushed = $notification->recipients()->where('push_status', 'sent')->count();
            $total = $notification->recipients()->count();

            return $redirect->with('success', "{$message} sent: push received by {$pushed} / {$total} customer(s) (the others see it in the app).");
        }

        return $redirect->with('error', "{$message} not sent: " . ($notification->cancel_reason ?: $notification->last_error ?: 'unknown error.'));
    }

    /**
     * Validates the form (create and edit).
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?MobileNotification $notification): array
    {
        $manual = !$notification || $notification->isManual();

        $destinations = $manual
            ? array_keys(NotificationDestinations::manual())
            : array_keys(NotificationDestinations::forType((string) $notification->auto_type));

        if ($notification && $notification->destination && !in_array($notification->destination, $destinations, true)) {
            $destinations[] = $notification->destination; // keep the current one
        }

        $data = $request->validate([
            'title_en' => ['required', 'string', 'max:255'],
            'title_ar' => ['required', 'string', 'max:255'],
            'body_en' => ['required', 'string', 'max:2000'],
            'body_ar' => ['required', 'string', 'max:2000'],
            'destination' => ['required', Rule::in($destinations)],
            'product_id' => ['nullable', 'required_if:destination,product', 'integer', 'exists:products,id'],
            'target_type' => [$manual ? 'required' : 'nullable', Rule::in(array_keys(MobileNotification::TARGETS))],
            'customers' => ['nullable', 'array'],
            'customers.*' => ['integer'],
            'when' => ['required', 'in:now,later'],
            'scheduled_at' => ['nullable', 'required_if:when,later', 'date'],
        ], [
            'product_id.required_if' => 'Choose the product to open.',
            'scheduled_at.required_if' => 'Choose the date and time of sending.',
        ]);

        $sendNow = $data['when'] === 'now';
        $scheduledAt = $sendNow ? now() : Carbon::parse($data['scheduled_at']);

        $params = $notification?->destination_params ?? [];

        if ($data['destination'] === 'product') {
            $params['product_id'] = (int) $data['product_id'];
        } elseif ($manual) {
            $params = [];
        }

        $target = $manual ? $data['target_type'] : $notification->target_type;
        $customerIds = [];

        if ($manual) {
            $customerIds = NotificationAudience::ids($target, $data['customers'] ?? []);

            if ($customerIds === []) {
                throw ValidationException::withMessages([
                    'customers' => $target === 'selected_customers'
                        ? 'Choose at least one customer.'
                        : 'No customer in this target for the moment.',
                ]);
            }
        }

        return [
            'title_en' => trim($data['title_en']),
            'title_ar' => trim($data['title_ar']),
            'body_en' => trim($data['body_en']),
            'body_ar' => trim($data['body_ar']),
            'destination' => $data['destination'],
            'destination_params' => $params,
            'target_type' => $target,
            'customer_ids' => $customerIds,
            'single_customer_id' => $manual
                ? ($target === 'selected_customers' && count($customerIds) === 1 ? $customerIds[0] : null)
                : $notification->customer_account_id,
            'send_now' => $sendNow,
            'scheduled_at' => $scheduledAt,
        ];
    }

    private function formData(MobileNotification $notification, array $customerIds): array
    {
        $manual = !$notification->exists || $notification->isManual();

        $customers = $customerIds
            ? CustomerAccount::with('subscription')->whereIn('id', $customerIds)->get()
                ->map(fn ($customer) => ['id' => $customer->id, 'label' => NotificationAudience::label($customer)])
                ->values()
            : collect();

        return [
            'notification' => $notification,
            'manual' => $manual,
            'destinations' => $manual
                ? NotificationDestinations::manual()
                : NotificationDestinations::forType((string) $notification->auto_type),
            'products' => Product::query()->orderBy('code')->get(['id', 'code', 'name']),
            'selectedCustomers' => $customers,
            'targetCounts' => $manual ? [
                'all_customers' => NotificationAudience::count('all_customers'),
                'active_plan' => NotificationAudience::count('active_plan'),
                'no_active_plan' => NotificationAudience::count('no_active_plan'),
            ] : [],
        ];
    }

    private function searchCustomers(string $q, int $limit)
    {
        $digits = preg_replace('/\D+/', '', $q);

        return NotificationAudience::customers()
            ->with('subscription')
            ->where(function ($query) use ($q, $digits) {
                if (ctype_digit($q)) {
                    $query->orWhere('customer_accounts.id', (int) $q);
                }

                if (strlen($digits) >= 3) {
                    $query->orWhere('customer_accounts.phone', 'like', '%' . $digits . '%');
                }

                $query->orWhereHas('subscription', function ($sub) use ($q, $digits) {
                    $sub->where('subscriber_first_name', 'like', "%{$q}%")
                        ->orWhere('subscriber_last_name', 'like', "%{$q}%")
                        ->orWhereRaw("CONCAT_WS(' ', subscriber_first_name, subscriber_last_name) LIKE ?", ["%{$q}%"])
                        ->orWhere('code', 'like', "%{$q}%");

                    if (strlen($digits) >= 3) {
                        $sub->orWhere('subscriber_phone', 'like', '%' . $digits . '%');
                    }
                });
            })
            ->orderByDesc('customer_accounts.id')
            ->limit($limit)
            ->get();
    }

    private function whereRead($query)
    {
        return $query->where(fn ($q) => $q->whereNotNull('viewed_at')->orWhereNotNull('opened_at'));
    }

    private function authorizePermission(string $permission): void
    {
        $user = auth()->user();

        abort_unless($user && $user->hasPermission($permission), 403);
    }
}
