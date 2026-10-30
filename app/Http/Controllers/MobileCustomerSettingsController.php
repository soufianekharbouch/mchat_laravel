<?php

namespace App\Http\Controllers;

use App\Models\CustomerAccount;
use App\Models\CustomerAddress;
use App\Models\SupportTicketMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class MobileCustomerSettingsController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | AUTHENTICATED CUSTOMER
    |--------------------------------------------------------------------------
    */

    private function customer(Request $request): ?CustomerAccount
    {
        $token = $request->bearerToken()
            ?? $request->input('access_token');

        if (!$token) {
            return null;
        }

        return CustomerAccount::where(
            'access_token_hash',
            hash('sha256', $token)
        )->first();
    }

    /*
    |--------------------------------------------------------------------------
    | LANGUAGE RESOLUTION
    |--------------------------------------------------------------------------
    |
    | Priority : "lang" sent by the app > customer settings > English.
    |
    */

    private function language(Request $request, CustomerAccount $customer): string
    {
        $settings = is_array($customer->settings) ? $customer->settings : [];

        $lang = $request->input('lang')
            ?? ($settings['language'] ?? 'en');

        return $lang === 'ar' ? 'ar' : 'en';
    }

    /*
    |--------------------------------------------------------------------------
    | ADDRESSES FORMATTED FOR THE APP
    |--------------------------------------------------------------------------
    */

    private function customerAddresses(CustomerAccount $customer, string $lang)
    {
        return CustomerAddress::with(['provinceRelation', 'zone'])
            ->where('customer_account_id', $customer->id)
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get()
            ->map(fn (CustomerAddress $address) => $address->toMobileArray($lang))
            ->values();
    }

    private function formatAddress(CustomerAddress $address, string $lang): array
    {
        return $address
            ->fresh(['provinceRelation', 'zone'])
            ->toMobileArray($lang);
    }


    /*
    |--------------------------------------------------------------------------
    | GET SETTINGS
    |--------------------------------------------------------------------------
    */

    public function show(Request $request)
    {
        $customer = $this->customer($request);

        if (!$customer) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $customer->load('subscription');

        $subscription = $customer->subscription;

        $lang = $this->language($request, $customer);

        $supportUnread = SupportTicketMessage::query()
            ->join('support_tickets', 'support_tickets.id', '=', 'support_ticket_messages.support_ticket_id')
            ->where('support_tickets.customer_account_id', $customer->id)
            ->where('support_ticket_messages.sender_type', 'admin')
            ->whereNull('support_ticket_messages.read_at')
            ->count();

        $addresses = $this->customerAddresses($customer, $lang);

        return response()->json([
            'customer' => [
                'id' => $customer->id,
                'first_name' => $subscription?->subscriber_first_name,
                'last_name' => $subscription?->subscriber_last_name,
                'phone' => $customer->phone,
                'email' => $customer->email,
            ],

            'counters' => [
                'addresses' => $addresses->count(),
                'support_unread' => $supportUnread,
            ],

            'settings' => $customer->settings ?? new \stdClass(),

            'addresses' => $addresses,
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE SETTINGS
    |--------------------------------------------------------------------------
    |
    | Used for extensible customer preferences:
    | language, notifications, etc.
    |
    */

    public function updateSettings(Request $request)
    {
        $customer = $this->customer($request);

        if (!$customer) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $validated = $request->validate([
            'settings' => ['required', 'array'],
        ]);

        $currentSettings = is_array($customer->settings)
            ? $customer->settings
            : [];

        $customer->settings = array_merge(
            $currentSettings,
            $validated['settings']
        );

        $customer->save();

        return response()->json([
            'message' => 'Settings updated successfully.',
            'settings' => $customer->settings,
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE LANGUAGE
    |--------------------------------------------------------------------------
    */

    public function updateLanguage(Request $request)
    {
        $customer = $this->customer($request);

        if (!$customer) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $validated = $request->validate([
            'language' => ['required', Rule::in(['en', 'ar'])],
        ]);

        $settings = is_array($customer->settings)
            ? $customer->settings
            : [];

        $settings['language'] = $validated['language'];

        $customer->settings = $settings;

        $customer->save();

        return response()->json([
            'message' => 'Language updated successfully.',
            'language' => $validated['language'],
            'settings' => $customer->settings,
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | CHANGE PASSWORD
    |--------------------------------------------------------------------------
    */

    public function updatePassword(Request $request)
    {
        $customer = $this->customer($request);

        if (!$customer) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (!Hash::check($validated['current_password'], $customer->password)) {
            return response()->json([
                'message' => 'Current password is incorrect.',
                'errors' => [
                    'current_password' => [
                        'Current password is incorrect.',
                    ],
                ],
            ], 422);
        }

        $customer->password = Hash::make($validated['password']);

        $customer->save();

        return response()->json([
            'message' => 'Password updated successfully.',
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE PHONE
    |--------------------------------------------------------------------------
    */

    public function updatePhone(Request $request)
    {
        $customer = $this->customer($request);

        if (!$customer) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $validated = $request->validate([
            'phone' => [
                'required',
                'string',
                'max:30',
                Rule::unique('customer_accounts', 'phone')->ignore($customer->id),
            ],
        ]);

        $customer->phone = trim($validated['phone']);

        $customer->save();

        return response()->json([
            'message' => 'Phone number updated successfully.',
            'phone' => $customer->phone,
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE EMAIL
    |--------------------------------------------------------------------------
    */

    public function updateEmail(Request $request)
    {
        $customer = $this->customer($request);

        if (!$customer) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $validated = $request->validate([
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('customer_accounts', 'email')->ignore($customer->id),
            ],
        ]);

        $customer->email = !empty($validated['email'])
            ? strtolower(trim($validated['email']))
            : null;

        $customer->save();

        return response()->json([
            'message' => 'Email updated successfully.',
            'email' => $customer->email,
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | GET ADDRESSES
    |--------------------------------------------------------------------------
    */

    public function addresses(Request $request)
    {
        $customer = $this->customer($request);

        if (!$customer) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        return response()->json([
            'addresses' => $this->customerAddresses(
                $customer,
                $this->language($request, $customer)
            ),
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | CREATE ADDRESS
    |--------------------------------------------------------------------------
    */

    public function storeAddress(Request $request)
    {
        $customer = $this->customer($request);

        if (!$customer) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $validated = $this->validateAddress($request);

        $lang = $this->language($request, $customer);

        return DB::transaction(function () use ($customer, $validated, $lang) {

            $hasAddress = CustomerAddress::where('customer_account_id', $customer->id)
                ->exists();

            /*
             * First address automatically
             * becomes the default address.
             */
            $isDefault = !$hasAddress || !empty($validated['is_default']);

            if ($isDefault) {
                CustomerAddress::where('customer_account_id', $customer->id)
                    ->update(['is_default' => false]);
            }

            /*
             * When zone_id is sent, the model fills
             * province_id, city and province automatically.
             */
            $address = CustomerAddress::create([
                'customer_account_id' => $customer->id,
                'label' => $validated['label'] ?? null,
                'first_name' => $validated['first_name'] ?? null,
                'last_name' => $validated['last_name'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'address' => $validated['address'],
                'province_id' => $validated['province_id'] ?? null,
                'zone_id' => $validated['zone_id'] ?? null,
                'city' => $validated['city'] ?? null,
                'province' => $validated['province'] ?? null,
                'postal_code' => $validated['postal_code'] ?? null,
                'country' => $validated['country'] ?? null,
                'note' => $validated['note'] ?? null,
                'delivery_slot' => $validated['delivery_slot'] ?? null,
                'is_default' => $isDefault,
            ]);

            return response()->json([
                'message' => 'Address created successfully.',
                'address' => $this->formatAddress($address, $lang),
            ], 201);
        });
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE ADDRESS
    |--------------------------------------------------------------------------
    */

    public function updateAddress(Request $request, CustomerAddress $address)
    {
        $customer = $this->customer($request);

        if (!$customer) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ((int) $address->customer_account_id !== (int) $customer->id) {
            return response()->json([
                'message' => 'Address not found.',
            ], 404);
        }

        $validated = $this->validateAddress($request);

        $lang = $this->language($request, $customer);

        return DB::transaction(function () use ($customer, $address, $validated, $lang) {

            if (!empty($validated['is_default'])) {
                CustomerAddress::where('customer_account_id', $customer->id)
                    ->where('id', '!=', $address->id)
                    ->update(['is_default' => false]);
            }

            $address->update([
                'label' => $validated['label'] ?? null,
                'first_name' => $validated['first_name'] ?? null,
                'last_name' => $validated['last_name'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'address' => $validated['address'],

                /*
                 * province_id / zone_id : kept when not sent
                 * (older app versions), cleared when sent as null.
                 */
                'province_id' => array_key_exists('province_id', $validated)
                    ? $validated['province_id']
                    : $address->province_id,

                'zone_id' => array_key_exists('zone_id', $validated)
                    ? $validated['zone_id']
                    : $address->zone_id,

                'city' => $validated['city'] ?? $address->city,
                'province' => $validated['province'] ?? $address->province,
                'postal_code' => $validated['postal_code'] ?? $address->postal_code,
                'country' => $validated['country'] ?? $address->country,
                'note' => $validated['note'] ?? null,

                /*
                 * delivery_slot : kept when not sent (older app versions).
                 */
                'delivery_slot' => array_key_exists('delivery_slot', $validated)
                    ? $validated['delivery_slot']
                    : $address->delivery_slot,

                /*
                 * Do not remove the default status
                 * simply by editing the address.
                 */
                'is_default' => !empty($validated['is_default'])
                    ? true
                    : $address->is_default,
            ]);

            return response()->json([
                'message' => 'Address updated successfully.',
                'address' => $this->formatAddress($address, $lang),
            ]);
        });
    }


    /*
    |--------------------------------------------------------------------------
    | SET DEFAULT ADDRESS
    |--------------------------------------------------------------------------
    */

    public function setDefaultAddress(Request $request, CustomerAddress $address)
    {
        $customer = $this->customer($request);

        if (!$customer) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ((int) $address->customer_account_id !== (int) $customer->id) {
            return response()->json([
                'message' => 'Address not found.',
            ], 404);
        }

        DB::transaction(function () use ($customer, $address) {

            CustomerAddress::where('customer_account_id', $customer->id)
                ->update(['is_default' => false]);

            $address->update(['is_default' => true]);
        });

        return response()->json([
            'message' => 'Default address updated successfully.',
            'address' => $this->formatAddress(
                $address,
                $this->language($request, $customer)
            ),
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | DELETE ADDRESS
    |--------------------------------------------------------------------------
    */

    public function destroyAddress(Request $request, CustomerAddress $address)
    {
        $customer = $this->customer($request);

        if (!$customer) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ((int) $address->customer_account_id !== (int) $customer->id) {
            return response()->json([
                'message' => 'Address not found.',
            ], 404);
        }

        DB::transaction(function () use ($customer, $address) {

            $wasDefault = (bool) $address->is_default;

            $address->delete();

            /*
             * If the default address was deleted,
             * automatically select another one.
             */
            if ($wasDefault) {
                $nextAddress = CustomerAddress::where('customer_account_id', $customer->id)
                    ->orderByDesc('id')
                    ->first();

                if ($nextAddress) {
                    $nextAddress->update(['is_default' => true]);
                }
            }
        });

        return response()->json([
            'message' => 'Address deleted successfully.',
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | ADDRESS VALIDATION
    |--------------------------------------------------------------------------
    |
    | province_id / zone_id are nullable so older app versions
    | (sending only city / province text) keep working.
    | Switch them to 'required' once every customer uses the new version.
    |
    */

    private function validateAddress(Request $request): array
    {
        $zoneExists = Rule::exists('zones', 'id');

        /*
         * The zone must belong to the selected province.
         */
        if ($request->filled('province_id')) {
            $zoneExists->where('province_id', (int) $request->input('province_id'));
        }

        return $request->validate([
            'label' => ['nullable', 'string', 'max:100'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['required', 'string', 'max:500'],

            'province_id' => ['nullable', 'integer', 'exists:provinces,id'],
            'zone_id' => ['nullable', 'integer', $zoneExists],

            'city' => ['nullable', 'string', 'max:150'],
            'province' => ['nullable', 'string', 'max:150'],
            'postal_code' => ['nullable', 'string', 'max:30'],
            'country' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
            'delivery_slot' => ['nullable', Rule::in(CustomerAddress::DELIVERY_SLOTS)],
            'is_default' => ['sometimes', 'boolean'],
        ], [
            'delivery_slot.in' => 'The selected delivery time slot is invalid.',
            'zone_id.exists' => 'The selected zone does not belong to the selected province.',
        ]);
    }
}
