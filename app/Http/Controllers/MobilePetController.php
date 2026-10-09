<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\CustomerAccount;
use App\Models\CustomerAppActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

class MobilePetController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | CREATE PET
    |--------------------------------------------------------------------------
    */

    public function store(Request $request): JsonResponse
    {
        try {
            $customerAccount = $this->resolveCustomerAccount($request);

            if (!$customerAccount) {
                return $this->unauthorizedResponse();
            }

            $validator = Validator::make(
                $request->all(),
                [
                    'name' => 'required|string|max:255',
                    'species' => 'required|in:Cat,Dog',
                    'breed_size_category' => 'nullable|in:Small,Medium,Large',
                    'breed' => 'nullable|string|max:255',
                    'weight_kg' => 'nullable|numeric|min:0|max:9999.99',
                    'sex' => 'nullable|in:Male,Female',
                    'allergies' => 'nullable|string|max:5000',
                    'preferences' => 'nullable|string|max:5000',
                    'age' => 'nullable|string|max:255',
                    'health_status' => 'nullable|string|max:255',
                    'note' => 'nullable|string|max:5000',
                    'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
                ]
            );

            $validator->after(
                function ($validator) use ($request) {
                    if (
                        $request->input('species') === 'Dog'
                        &&
                        !$request->filled('breed_size_category')
                    ) {
                        $validator->errors()->add(
                            'breed_size_category',
                            'Breed size category is required for dogs.'
                        );
                    }
                }
            );

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $subscription = $customerAccount->subscription;

            if (!$subscription) {
                return response()->json([
                    'success' => false,
                    'message' => 'No subscription is linked to this account.',
                ], 404);
            }

            $species = $request->input('species');

            $animal = Animal::create([
                'subscription_id' => $subscription->id,
                'name' => trim($request->input('name')),
                'species' => $species,

                'breed_size_category' =>
                    $species === 'Dog'
                        ? $request->input('breed_size_category')
                        : null,

                'breed' => $request->input('breed'),
                'weight_kg' => $request->input('weight_kg'),
                'sex' => $request->input('sex'),
                'allergies' => $request->input('allergies'),
                'preferences' => $request->input('preferences'),
                'age' => $request->input('age'),
                'health_status' => $request->input('health_status'),
                'note' => $request->input('note'),

                'transition' => false,
                'last_order' => false,

                'subscription_start' => null,
                'subscription_end' => null,
            ]);

            /*
            |--------------------------------------------------------------------------
            | PHOTO
            |--------------------------------------------------------------------------
            */

            if ($request->hasFile('photo')) {
                $animal->photo_path =
                    $request
                        ->file('photo')
                        ->store(
                            'pets/' . $animal->id,
                            'public'
                        );

                $animal->save();
            }

            /*
            |--------------------------------------------------------------------------
            | ACTIVITY : PET CREATED
            |--------------------------------------------------------------------------
            */

            $this->recordActivity(
                $customerAccount,
                'PET_CREATED',
                [
                    'pet_id' => $animal->id,
                    'pet_name' => $animal->name,
                    'species' => $animal->species,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | ACTIVITY : PHOTO ADDED DURING CREATION
            |--------------------------------------------------------------------------
            */

            if ($request->hasFile('photo')) {
                $this->recordActivity(
                    $customerAccount,
                    'PET_PHOTO_CHANGED',
                    [
                        'pet_id' => $animal->id,
                        'pet_name' => $animal->name,
                    ]
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Pet created successfully.',
                'pet' => $this->formatAnimal($animal),
            ], 201);

        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Unable to create pet.',
            ], 500);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE PET
    |--------------------------------------------------------------------------
    */

    public function update(
        Request $request,
        Animal $pet
    ): JsonResponse {
        try {
            $customerAccount = $this->resolveCustomerAccount($request);

            if (!$customerAccount) {
                return $this->unauthorizedResponse();
            }

            /*
            |--------------------------------------------------------------------------
            | SECURITY : VERIFY PET OWNERSHIP
            |--------------------------------------------------------------------------
            */

            if (
                (int) $pet->subscription_id
                !==
                (int) $customerAccount->subscription_id
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pet not found.',
                ], 404);
            }

            $validator = Validator::make(
                $request->all(),
                [
                    'name' => 'sometimes|required|string|max:255',
                    'species' => 'sometimes|required|in:Cat,Dog',
                    'breed_size_category' => 'nullable|in:Small,Medium,Large',
                    'breed' => 'nullable|string|max:255',
                    'weight_kg' => 'nullable|numeric|min:0|max:9999.99',
                    'sex' => 'nullable|in:Male,Female',
                    'allergies' => 'nullable|string|max:5000',
                    'preferences' => 'nullable|string|max:5000',
                    'age' => 'nullable|string|max:255',
                    'health_status' => 'nullable|string|max:255',
                    'note' => 'nullable|string|max:5000',
                    'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
                    'remove_photo' => 'nullable|boolean',
                ]
            );

            $validator->after(
                function ($validator) use ($request, $pet) {
                    $species =
                        $request->input(
                            'species',
                            $pet->species
                        );

                    $breed =
                        $request->has('breed_size_category')
                            ? $request->input('breed_size_category')
                            : $pet->breed_size_category;

                    if (
                        $species === 'Dog'
                        &&
                        empty($breed)
                    ) {
                        $validator->errors()->add(
                            'breed_size_category',
                            'Breed size category is required for dogs.'
                        );
                    }
                }
            );

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | SAVE OLD VALUES
            |--------------------------------------------------------------------------
            */

            $oldValues = [
                'name' => $pet->name,
                'species' => $pet->species,
                'breed_size_category' => $pet->breed_size_category,
                'breed' => $pet->breed,
                'weight_kg' => $pet->weight_kg,
                'sex' => $pet->sex,
                'allergies' => $pet->allergies,
                'preferences' => $pet->preferences,
                'age' => $pet->age,
                'health_status' => $pet->health_status,
                'note' => $pet->note,
            ];

            /*
            |--------------------------------------------------------------------------
            | UPDATE VALUES
            |--------------------------------------------------------------------------
            */

            if ($request->has('name')) {
                $pet->name =
                    trim(
                        (string) $request->input('name')
                    );
            }

            if ($request->has('species')) {
                $pet->species =
                    $request->input('species');
            }

            if ($pet->species === 'Dog') {
                if ($request->has('breed_size_category')) {
                    $pet->breed_size_category =
                        $request->input('breed_size_category');
                }
            } else {
                $pet->breed_size_category = null;
            }

            if ($request->has('age')) {
                $pet->age =
                    $request->input('age');
            }

            if ($request->has('health_status')) {
                $pet->health_status =
                    $request->input('health_status');
            }

            if ($request->has('note')) {
                $pet->note =
                    $request->input('note');
            }

            foreach (['breed', 'weight_kg', 'sex', 'allergies', 'preferences'] as $field) {
                if ($request->exists($field)) {
                    $pet->{$field} = $request->input($field);
                }
            }

            $pet->save();

            /*
            |--------------------------------------------------------------------------
            | DETECT CHANGED FIELDS
            |--------------------------------------------------------------------------
            */

            $changedFields = [];

            foreach ($oldValues as $field => $oldValue) {
                if (
                    (string) $oldValue
                    !==
                    (string) $pet->{$field}
                ) {
                    $changedFields[] = $field;
                }
            }

            /*
            |--------------------------------------------------------------------------
            | UPDATE PHOTO IF PROVIDED
            |--------------------------------------------------------------------------
            */

            $photoChanged = false;
            $photoRemoved = false;

            /*
             * remove_photo has priority only when no replacement
             * photo was uploaded.
             */
            if (
                $request->boolean('remove_photo')
                &&
                !$request->hasFile('photo')
            ) {
                if (!empty($pet->photo_path)) {
                    $this->deletePetPhoto($pet);

                    $pet->photo_path = null;
                    $pet->save();

                    $photoChanged = true;
                    $photoRemoved = true;
                }
            }

            /*
             * If a new photo is supplied, replace the old one.
             */
            if ($request->hasFile('photo')) {
                $this->deletePetPhoto($pet);

                $pet->photo_path =
                    $request
                        ->file('photo')
                        ->store(
                            'pets/' . $pet->id,
                            'public'
                        );

                $pet->save();

                $photoChanged = true;
                $photoRemoved = false;
            }

            /*
            |--------------------------------------------------------------------------
            | ACTIVITY : PET UPDATED
            |--------------------------------------------------------------------------
            */

            if (!empty($changedFields)) {
                $this->recordActivity(
                    $customerAccount,
                    'PET_UPDATED',
                    [
                        'pet_id' => $pet->id,
                        'pet_name' => $pet->name,

                        'fields' =>
                            implode(
                                ', ',
                                $changedFields
                            ),
                    ]
                );
            }

            /*
            |--------------------------------------------------------------------------
            | ACTIVITY : PHOTO CHANGED
            |--------------------------------------------------------------------------
            */

            if ($photoChanged) {
                $this->recordActivity(
                    $customerAccount,
                    'PET_PHOTO_CHANGED',
                    [
                        'pet_id' => $pet->id,
                        'pet_name' => $pet->name,
                        'action' =>
                            $photoRemoved
                                ? 'removed'
                                : 'changed',
                    ]
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Pet updated successfully.',
                'pet' => $this->formatAnimal($pet),
            ]);

        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Unable to update pet.',
            ], 500);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE PET PHOTO
    |--------------------------------------------------------------------------
    */

    public function updatePhoto(
        Request $request,
        Animal $pet
    ): JsonResponse {
        try {
            $customerAccount = $this->resolveCustomerAccount($request);

            if (!$customerAccount) {
                return $this->unauthorizedResponse();
            }

            if (
                (int) $pet->subscription_id
                !==
                (int) $customerAccount->subscription_id
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pet not found.',
                ], 404);
            }

            $validator = Validator::make(
                $request->all(),
                [
                    'photo' =>
                        'required|image|mimes:jpg,jpeg,png,webp|max:4096',
                ]
            );

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | DELETE OLD PHOTO
            |--------------------------------------------------------------------------
            */

            $this->deletePetPhoto($pet);

            /*
            |--------------------------------------------------------------------------
            | STORE NEW PHOTO
            |--------------------------------------------------------------------------
            */

            $pet->photo_path =
                $request
                    ->file('photo')
                    ->store(
                        'pets/' . $pet->id,
                        'public'
                    );

            $pet->save();

            /*
            |--------------------------------------------------------------------------
            | ACTIVITY
            |--------------------------------------------------------------------------
            */

            $this->recordActivity(
                $customerAccount,
                'PET_PHOTO_CHANGED',
                [
                    'pet_id' => $pet->id,
                    'pet_name' => $pet->name,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Pet photo updated successfully.',
                'pet' => $this->formatAnimal($pet),
            ]);

        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Unable to update pet photo.',
            ], 500);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | DELETE PET
    |--------------------------------------------------------------------------
    */

    public function destroy(
        Request $request,
        Animal $pet
    ): JsonResponse {
        try {
            $customerAccount = $this->resolveCustomerAccount($request);

            if (!$customerAccount) {
                return $this->unauthorizedResponse();
            }

            if (
                (int) $pet->subscription_id
                !==
                (int) $customerAccount->subscription_id
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pet not found.',
                ], 404);
            }

            /*
            |--------------------------------------------------------------------------
            | PROTECT PETS WITH BUSINESS DATA
            |--------------------------------------------------------------------------
            */

            if (
                $pet->mealDates()->exists()
                ||
                $pet->plannedOrders()->exists()
            ) {
                return response()->json([
                    'success' => false,

                    'message' =>
                        'This pet cannot be deleted because it already has orders or meal data.',
                ], 409);
            }

            $petId = $pet->id;
            $petName = $pet->name;

            /*
            |--------------------------------------------------------------------------
            | DELETE PHOTO
            |--------------------------------------------------------------------------
            */

            $this->deletePetPhoto($pet);

            /*
            |--------------------------------------------------------------------------
            | DELETE PET
            |--------------------------------------------------------------------------
            */

            $pet->delete();

            /*
            |--------------------------------------------------------------------------
            | ACTIVITY
            |--------------------------------------------------------------------------
            */

            $this->recordActivity(
                $customerAccount,
                'PET_DELETED',
                [
                    'pet_id' => $petId,
                    'pet_name' => $petName,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Pet deleted successfully.',
            ]);

        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Unable to delete pet.',
            ], 500);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | RESOLVE AUTHENTICATED CUSTOMER
    |--------------------------------------------------------------------------
    */

    private function resolveCustomerAccount(
        Request $request
    ): ?CustomerAccount {
        $plainToken =
            trim(
                (string) $request->input(
                    'access_token',
                    ''
                )
            );

        if ($plainToken === '') {
            return null;
        }

        return CustomerAccount::query()
            ->where(
                'access_token_hash',
                hash(
                    'sha256',
                    $plainToken
                )
            )
            ->whereNotNull(
                'access_token_expires_at'
            )
            ->where(
                'access_token_expires_at',
                '>',
                now()
            )
            ->first();
    }


    /*
    |--------------------------------------------------------------------------
    | FORMAT PET RESPONSE
    |--------------------------------------------------------------------------
    */

    private function formatAnimal(
        Animal $animal
    ): array {
        return [
            'id' => $animal->id,

            'name' => $animal->name,

            'species' => $animal->species,

            'breed_size_category' =>
                $animal->breed_size_category,

            'breed' => $animal->breed,
            'weight_kg' => $animal->weight_kg,
            'sex' => $animal->sex,
            'allergies' => $animal->allergies,
            'preferences' => $animal->preferences,
            'age' => $animal->age,

            'health_status' =>
                $animal->health_status,

            'note' => $animal->note,

            'photo_url' =>
                $animal->photo_path
                    ? url(
                        '/storage/app/public/'
                        . ltrim(
                            $animal->photo_path,
                            '/'
                        )
                    )
                    : null,

            'transition' =>
                (bool) $animal->transition,

            'subscription_start' =>
                optional(
                    $animal->subscription_start
                )->format('Y-m-d'),

            'subscription_end' =>
                optional(
                    $animal->subscription_end
                )->format('Y-m-d'),
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | DELETE PET PHOTO
    |--------------------------------------------------------------------------
    */

    private function deletePetPhoto(
        Animal $pet
    ): void {
        if (
            !empty($pet->photo_path)
            &&
            Storage::disk('public')->exists(
                $pet->photo_path
            )
        ) {
            Storage::disk('public')->delete(
                $pet->photo_path
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | RECORD CUSTOMER ACTIVITY
    |--------------------------------------------------------------------------
    */

    private function recordActivity(
        CustomerAccount $customerAccount,
        string $code,
        ?array $metadata = null
    ): void {
        CustomerAppActivity::record(
            $customerAccount->id,
            $code,
            $metadata
        );
    }


    /*
    |--------------------------------------------------------------------------
    | UNAUTHORIZED
    |--------------------------------------------------------------------------
    */

    private function unauthorizedResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' =>
                'Your session is invalid or has expired.',
        ], 401);
    }
}