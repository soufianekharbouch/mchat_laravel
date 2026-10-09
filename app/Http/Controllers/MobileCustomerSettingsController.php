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

        $supportUnread = SupportTicketMessage::query()
            ->join('support_tickets', 'support_tickets.id', '=', 'support_ticket_messages.support_ticket_id')
            ->where('support_tickets.customer_account_id', $customer->id)
            ->where('support_ticket_messages.sender_type', 'admin')
            ->whereNull('support_ticket_messages.read_at')
            ->count();
    
        return response()->json([
            'customer' => [
                'id' => $customer->id,
    
                'first_name' =>
                    $subscription?->subscriber_first_name,
    
                'last_name' =>
                    $subscription?->subscriber_last_name,
    
                'phone' => $customer->phone,
    
                'email' => $customer->email,
            ],
    
            'counters' => [
                'addresses' => $customer
                    ->addresses()
                    ->count(),
                'support_unread' => $supportUnread,
            ],
    
            'settings' =>
                $customer->settings
                ?? new \stdClass(),
    
            'addresses' =>
                $customer
                    ->addresses()
                    ->orderByDesc('is_default')
                    ->orderByDesc('id')
                    ->get(),
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
            'settings' => [
                'required',
                'array',
            ],
        ]);

        $currentSettings =
            is_array($customer->settings)
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
            'language' => [
                'required',
                Rule::in([
                    'en',
                    'ar',
                ]),
            ],
        ]);

        $settings =
            is_array($customer->settings)
                ? $customer->settings
                : [];

        $settings['language'] =
            $validated['language'];

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
            'current_password' => [
                'required',
                'string',
            ],

            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
            ],
        ]);

        if (
            !Hash::check(
                $validated['current_password'],
                $customer->password
            )
        ) {
            return response()->json([
                'message' => 'Current password is incorrect.',

                'errors' => [
                    'current_password' => [
                        'Current password is incorrect.',
                    ],
                ],
            ], 422);
        }

        $customer->password = Hash::make(
            $validated['password']
        );

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

                Rule::unique(
                    'customer_accounts',
                    'phone'
                )->ignore($customer->id),
            ],
        ]);

        $customer->phone =
            trim($validated['phone']);

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

                Rule::unique(
                    'customer_accounts',
                    'email'
                )->ignore($customer->id),
            ],
        ]);

        $customer->email =
            !empty($validated['email'])
                ? strtolower(
                    trim($validated['email'])
                )
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

        $addresses = CustomerAddress::where(
            'customer_account_id',
            $customer->id
        )
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'addresses' => $addresses,
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

        $validated = $this->validateAddress(
            $request
        );

        return DB::transaction(
            function () use (
                $customer,
                $validated
            ) {

                $hasAddress =
                    CustomerAddress::where(
                        'customer_account_id',
                        $customer->id
                    )->exists();

                /*
                 * First address automatically
                 * becomes the default address.
                 */

                $isDefault =
                    !$hasAddress
                    || !empty(
                        $validated['is_default']
                    );

                if ($isDefault) {
                    CustomerAddress::where(
                        'customer_account_id',
                        $customer->id
                    )->update([
                        'is_default' => false,
                    ]);
                }

                $address =
                    CustomerAddress::create([
                        'customer_account_id' =>
                            $customer->id,

                        'label' =>
                            $validated['label']
                            ?? null,

                        'first_name' =>
                            $validated['first_name']
                            ?? null,

                        'last_name' =>
                            $validated['last_name']
                            ?? null,

                        'phone' =>
                            $validated['phone']
                            ?? null,

                        'address' =>
                            $validated['address'],

                        'city' =>
                            $validated['city']
                            ?? null,

                        'province' =>
                            $validated['province']
                            ?? null,

                        'postal_code' =>
                            $validated['postal_code']
                            ?? null,

                        'country' =>
                            $validated['country']
                            ?? null,

                        'note' =>
                            $validated['note']
                            ?? null,

                        'is_default' =>
                            $isDefault,
                    ]);

                return response()->json([
                    'message' => 'Address created successfully.',
                    'address' => $address,
                ], 201);
            }
        );
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE ADDRESS
    |--------------------------------------------------------------------------
    */

    public function updateAddress(
        Request $request,
        CustomerAddress $address
    ) {
        $customer = $this->customer($request);

        if (!$customer) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (
            (int) $address->customer_account_id
            !==
            (int) $customer->id
        ) {
            return response()->json([
                'message' => 'Address not found.',
            ], 404);
        }

        $validated = $this->validateAddress(
            $request
        );

        return DB::transaction(
            function () use (
                $customer,
                $address,
                $validated
            ) {

                if (
                    !empty(
                        $validated['is_default']
                    )
                ) {
                    CustomerAddress::where(
                        'customer_account_id',
                        $customer->id
                    )
                        ->where(
                            'id',
                            '!=',
                            $address->id
                        )
                        ->update([
                            'is_default' => false,
                        ]);
                }

                $address->update([
                    'label' =>
                        $validated['label']
                        ?? null,

                    'first_name' =>
                        $validated['first_name']
                        ?? null,

                    'last_name' =>
                        $validated['last_name']
                        ?? null,

                    'phone' =>
                        $validated['phone']
                        ?? null,

                    'address' =>
                        $validated['address'],

                    'city' =>
                        $validated['city']
                        ?? $address->city,

                    'province' =>
                        $validated['province']
                        ?? $address->province,

                    'postal_code' =>
                        $validated['postal_code']
                        ?? $address->postal_code,

                    'country' =>
                        $validated['country']
                        ?? $address->country,

                    'note' =>
                        $validated['note']
                        ?? null,

                    /*
                     * Do not remove the default status
                     * simply by editing the address.
                     */
                    'is_default' =>
                        !empty(
                            $validated['is_default']
                        )
                            ? true
                            : $address->is_default,
                ]);

                return response()->json([
                    'message' => 'Address updated successfully.',
                    'address' => $address->fresh(),
                ]);
            }
        );
    }


    /*
    |--------------------------------------------------------------------------
    | SET DEFAULT ADDRESS
    |--------------------------------------------------------------------------
    */

    public function setDefaultAddress(
        Request $request,
        CustomerAddress $address
    ) {
        $customer = $this->customer($request);

        if (!$customer) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (
            (int) $address->customer_account_id
            !==
            (int) $customer->id
        ) {
            return response()->json([
                'message' => 'Address not found.',
            ], 404);
        }

        DB::transaction(
            function () use (
                $customer,
                $address
            ) {

                CustomerAddress::where(
                    'customer_account_id',
                    $customer->id
                )->update([
                    'is_default' => false,
                ]);

                $address->update([
                    'is_default' => true,
                ]);
            }
        );

        return response()->json([
            'message' => 'Default address updated successfully.',
            'address' => $address->fresh(),
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | DELETE ADDRESS
    |--------------------------------------------------------------------------
    */

    public function destroyAddress(
        Request $request,
        CustomerAddress $address
    ) {
        $customer = $this->customer($request);

        if (!$customer) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (
            (int) $address->customer_account_id
            !==
            (int) $customer->id
        ) {
            return response()->json([
                'message' => 'Address not found.',
            ], 404);
        }

        DB::transaction(
            function () use (
                $customer,
                $address
            ) {

                $wasDefault =
                    (bool) $address->is_default;

                $address->delete();

                /*
                 * If the default address was deleted,
                 * automatically select another one.
                 */

                if ($wasDefault) {

                    $nextAddress =
                        CustomerAddress::where(
                            'customer_account_id',
                            $customer->id
                        )
                            ->orderByDesc('id')
                            ->first();

                    if ($nextAddress) {
                        $nextAddress->update([
                            'is_default' => true,
                        ]);
                    }
                }
            }
        );

        return response()->json([
            'message' => 'Address deleted successfully.',
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | ADDRESS VALIDATION
    |--------------------------------------------------------------------------
    */

    private function validateAddress(
        Request $request
    ): array {
        return $request->validate([
            'label' => [
                'nullable',
                'string',
                'max:100',
            ],

            'first_name' => [
                'nullable',
                'string',
                'max:100',
            ],

            'last_name' => [
                'nullable',
                'string',
                'max:100',
            ],

            'phone' => [
                'nullable',
                'string',
                'max:30',
            ],

            'address' => [
                'required',
                'string',
                'max:500',
            ],

            'city' => [
                'nullable',
                'string',
                'max:150',
            ],

            'province' => [
                'nullable',
                'string',
                'max:150',
            ],

            'postal_code' => [
                'nullable',
                'string',
                'max:30',
            ],

            'country' => [
                'nullable',
                'string',
                'max:100',
            ],

            'note' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'is_default' => [
                'sometimes',
                'boolean',
            ],
        ]);
    }
}