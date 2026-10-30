<?php

namespace App\Http\Controllers;

use App\Support\SubscriptionCode;
use App\Models\Animal;
use App\Models\CustomerAccount;
use App\Models\CustomerAddress;
use App\Models\CustomerAppActivity;
use App\Models\Subscription;
use App\Models\Zone;
use App\Services\OtpException;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class MobileRegisterController extends Controller
{
    /**
     * Inscription d'un nouveau client depuis l'application mobile.
     *
     * POST /api/mobile/register
     *
     * Crée dans une seule transaction :
     *   1. l'abonnement (Subscription) avec les informations du client
     *   2. le compte client (CustomerAccount) avec son propre mot de passe
     *   3. l'adresse de livraison par défaut (CustomerAddress)
     *   4. les animaux éventuels (Animal)
     *
     * Puis connecte directement le client (même réponse que /login).
     */
    public function register(Request $request): JsonResponse
    {
        try {

            /*
            |--------------------------------------------------------------------------
            | NORMALIZE PHONE / EMAIL BEFORE VALIDATION
            |--------------------------------------------------------------------------
            |
            | Même format que CustomerAuthController::login :
            | "+965 5555 5555" devient "96555555555".
            |
            */

            $request->merge([
                'phone' => $this->normalizePhone($request->input('phone')),
                'email' => $request->filled('email')
                    ? strtolower(trim((string) $request->input('email')))
                    : null,
            ]);

            /*
            |--------------------------------------------------------------------------
            | VALIDATION
            |--------------------------------------------------------------------------
            */

            $zoneExists = Rule::exists('zones', 'id');

            if ($request->filled('address.province_id')) {
                $zoneExists->where(
                    'province_id',
                    (int) $request->input('address.province_id')
                );
            }

            $validator = Validator::make(
                $request->all(),
                [
                    // Customer
                    'first_name' => ['required', 'string', 'max:100'],
                    'last_name' => ['required', 'string', 'max:100'],
                    'email' => [
                        'required',
                        'email',
                        'max:255',
                        Rule::unique('customer_accounts', 'email'),
                    ],
                    'phone' => [
                        'required',
                        'string',
                        'min:8',
                        'max:20',
                        Rule::unique('customer_accounts', 'phone'),
                    ],
                    'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
                    'locale' => ['nullable', 'in:en,ar'],
                    'otp_code' => ['nullable', 'string', 'max:10'],

                    // Address
                    'address' => ['required', 'array'],
                    'address.label' => ['nullable', 'string', 'max:100'],
                    'address.province_id' => ['required', 'integer', 'exists:provinces,id'],
                    'address.zone_id' => ['required', 'integer', $zoneExists],
                    'address.address' => ['required', 'string', 'max:500'],
                    'address.note' => ['nullable', 'string', 'max:1000'],
                    'address.delivery_slot' => ['nullable', Rule::in(CustomerAddress::DELIVERY_SLOTS)],

                    // Pets (optional)
                    'pets' => ['nullable', 'array', 'max:10'],
                    'pets.*.name' => ['required', 'string', 'max:255'],
                    'pets.*.species' => ['required', 'in:Cat,Dog'],
                    'pets.*.breed_size_category' => ['nullable', 'in:Small,Medium,Large'],
                    'pets.*.breed' => ['nullable', 'string', 'max:255'],
                    'pets.*.weight_kg' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
                    'pets.*.sex' => ['nullable', 'in:Male,Female'],
                    'pets.*.allergies' => ['nullable', 'string', 'max:5000'],
                    'pets.*.preferences' => ['nullable', 'string', 'max:5000'],
                    'pets.*.age' => ['nullable', 'string', 'max:255'],
                    'pets.*.note' => ['nullable', 'string', 'max:5000'],
                ],
                [
                    'first_name.required' => 'Please enter your first name.',
                    'last_name.required' => 'Please enter your last name.',
                    'email.required' => 'Please enter your email address.',
                    'email.email' => 'Please enter a valid email address.',
                    'email.unique' => 'An account already exists with this email address.',
                    'phone.required' => 'Please enter your phone number.',
                    'phone.min' => 'Please enter a valid phone number.',
                    'phone.unique' => 'An account already exists with this phone number. Please sign in.',
                    'password.min' => 'Password must be at least 8 characters.',
                    'password.confirmed' => 'Passwords do not match.',
                    'address.province_id.required' => 'Please select a governorate.',
                    'address.zone_id.required' => 'Please select an area.',
                    'address.zone_id.exists' => 'The selected area does not belong to the selected governorate.',
                    'address.address.required' => 'Please enter your address.',
                    'pets.*.name.required' => 'Please enter a name for each pet.',
                    'pets.*.species.in' => 'Pet species must be Cat or Dog.',
                ]
            );

            /*
             * Même règle que MobilePetController :
             * la taille est obligatoire pour un chien.
             * + le téléphone ne doit pas déjà appartenir
             *   à un abonnement existant (client Shopify / back-office).
             */
            $validator->after(function ($validator) use ($request) {

                foreach ((array) $request->input('pets', []) as $index => $pet) {
                    if (
                        ($pet['species'] ?? null) === 'Dog'
                        && empty($pet['breed_size_category'])
                    ) {
                        $validator->errors()->add(
                            "pets.$index.breed_size_category",
                            'Please select the size of each dog.'
                        );
                    }
                }

                $phone = (string) $request->input('phone');

                if ($phone !== '' && $this->subscriptionPhoneExists($phone)) {
                    $validator->errors()->add(
                        'phone',
                        'This phone number is already linked to an existing customer. Please sign in or contact our support team.'
                    );
                }
            });

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors(),
                ], 422);
            }

            $data = $validator->validated();
            $locale = $data['locale'] ?? 'en';

            /*
            |--------------------------------------------------------------------------
            | WHATSAPP OTP (Zoko)
            |--------------------------------------------------------------------------
            |
            | When ZOKO_OTP_ENABLED=true the phone must be confirmed with the
            | code sent by POST /mobile/otp/send (purpose "register").
            | The code is consumed here, only after all other fields are valid.
            |
            */

            if (OtpService::enabled()) {
                try {
                    app(OtpService::class)->verify(
                        $data['phone'],
                        OtpService::PURPOSE_REGISTER,
                        $request->input('otp_code'),
                        $locale
                    );
                } catch (OtpException $exception) {
                    return response()->json(array_merge([
                        'success' => false,
                        'otp_error' => true,
                        'reason' => $exception->reason,
                        'message' => $exception->getMessage(),
                        'errors' => ['otp_code' => [$exception->getMessage()]],
                    ], $exception->extra), 422);
                }
            }

            $zone = Zone::with('province')->findOrFail($data['address']['zone_id']);

            /*
            |--------------------------------------------------------------------------
            | CREATE EVERYTHING IN ONE TRANSACTION
            |--------------------------------------------------------------------------
            */

            $plainToken = Str::random(64);

            [$customerAccount, $animals] = DB::transaction(
                function () use ($data, $zone, $locale, $plainToken) {

                    /*
                     * 1. Subscription
                     */
                    $subscription = Subscription::create([
                        // Same format as the back-office + -APP: C0300565-APP
                        'code' => SubscriptionCode::next($zone->province, true),
                        'creation_date' => now()->toDateString(),

                        'subscriber_first_name' => trim($data['first_name']),
                        'subscriber_last_name' => trim($data['last_name']),
                        'subscriber_phone' => $data['phone'],

                        'subscriber_address' => trim($data['address']['address']),
                        'subscriber_note' => $data['address']['note'] ?? null,
                        'subscriber_province' => $zone->province?->name,
                        'subscriber_zone' => $zone->name,

                        'subscriber_delivery_slot' => $data['address']['delivery_slot'] ?? null,
                        'delivery_days' => [],
                        'day_recipes' => [],
                    ]);

                    /*
                     * 2. Customer account
                     *
                     * Le client a choisi son mot de passe :
                     * password_changed_at = now(), pas de mot de passe initial
                     * => must_change_password = false.
                     *
                     * 'password' => 'hashed' (cast) : pas de Hash::make().
                     */
                    $customerAccount = CustomerAccount::create([
                        'subscription_id' => $subscription->id,
                        'phone' => $data['phone'],
                        'email' => $data['email'],
                        'password' => $data['password'],

                        'settings' => ['language' => $locale],

                        'initial_password_encrypted' => null,
                        'password_changed_at' => now(),
                    ]);

                    $customerAccount->forceFill([
                        'access_token_hash' => hash('sha256', $plainToken),
                        'access_token_expires_at' => now()->addHours(2),
                        'last_login_at' => now(),
                        'last_app_activity_at' => now(),
                        'login_count' => 1,
                    ])->save();

                    /*
                     * 3. Default delivery address
                     *
                     * Le modèle CustomerAddress remplit automatiquement
                     * city / province (texte anglais) à partir de zone_id.
                     */
                    CustomerAddress::create([
                        'customer_account_id' => $customerAccount->id,
                        'label' => $data['address']['label'] ?? null,
                        'first_name' => trim($data['first_name']),
                        'last_name' => trim($data['last_name']),
                        'phone' => $data['phone'],
                        'address' => trim($data['address']['address']),
                        'note' => $data['address']['note'] ?? null,
                        'delivery_slot' => $data['address']['delivery_slot'] ?? null,
                        'province_id' => $zone->province_id,
                        'zone_id' => $zone->id,
                        'is_default' => true,
                    ]);

                    /*
                     * 4. Pets (optional)
                     */
                    $animals = [];

                    foreach ($data['pets'] ?? [] as $pet) {
                        $species = $pet['species'];

                        $animals[] = Animal::create([
                            'subscription_id' => $subscription->id,
                            'name' => trim($pet['name']),
                            'species' => $species,
                            'breed_size_category' => $species === 'Dog'
                                ? ($pet['breed_size_category'] ?? null)
                                : null,
                            'breed' => $pet['breed'] ?? null,
                            'weight_kg' => $pet['weight_kg'] ?? null,
                            'sex' => $pet['sex'] ?? null,
                            'allergies' => $pet['allergies'] ?? null,
                            'preferences' => $pet['preferences'] ?? null,
                            'age' => $pet['age'] ?? null,
                            'health_status' => null,
                            'note' => $pet['note'] ?? null,

                            'transition' => false,
                            'last_order' => false,
                            'subscription_start' => null,
                            'subscription_end' => null,
                        ]);
                    }

                    return [$customerAccount, $animals];
                }
            );

            /*
            |--------------------------------------------------------------------------
            | ACTIVITY HISTORY (outside the transaction: never blocks sign-up)
            |--------------------------------------------------------------------------
            */

            foreach ($animals as $animal) {
                $this->recordActivity($customerAccount, 'PET_CREATED', [
                    'pet_id' => $animal->id,
                    'pet_name' => $animal->name,
                    'species' => $animal->species,
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | RESPONSE (same format as /login)
            |--------------------------------------------------------------------------
            */

            return response()->json([
                'success' => true,
                'message' => 'Your account has been created successfully.',
                'access_token' => $plainToken,
                'expires_at' => optional($customerAccount->access_token_expires_at)->toIso8601String(),
                'locale' => $locale,
                'must_change_password' => false,
                'customer' => [
                    'id' => $customerAccount->id,
                    'subscription_id' => $customerAccount->subscription_id,
                    'pets_count' => count($animals),
                ],
            ], 201);

        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'An internal server error occurred during sign up.',
            ], 500);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    /**
     * "+965 5555 5555" -> "96555555555"
     */
    private function normalizePhone(?string $phone): string
    {
        return preg_replace('/[^0-9]/', '', (string) $phone) ?? '';
    }

    /**
     * Vérifie si un abonnement existant utilise déjà ce numéro
     * (stocké avec ou sans espaces, +, tirets, parenthèses).
     */
    private function subscriptionPhoneExists(string $normalizedPhone): bool
    {
        return Subscription::query()
            ->whereRaw(
                "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(subscriber_phone, '+', ''), ' ', ''), '-', ''), '(', ''), ')', '') = ?",
                [$normalizedPhone]
            )
            ->exists();
    }

    private function recordActivity(
        CustomerAccount $customerAccount,
        string $code,
        ?array $metadata = null
    ): void {
        try {
            CustomerAppActivity::record($customerAccount->id, $code, $metadata);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
