<?php

namespace App\Http\Controllers;

use App\Models\CustomerAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class CustomerAuthController extends Controller
{
    /**
     * Connexion du client.
     *
     * Cette méthode retourne toujours du JSON.
     * Elle ne fait aucune redirection.
     */
    public function login(
        Request $request
    ): JsonResponse {
        try {

            $validator =
                Validator::make(
                    $request->all(),
                    [
                        'phone' => [
                            'required',
                            'string',
                            'max:30',
                        ],

                        'password' => [
                            'required',
                            'string',
                            'max:255',
                        ],

                        'locale' => [
                            'nullable',
                            'in:en,ar',
                        ],
                    ],
                    [
                        'phone.required' =>
                            'Please enter your phone number.',

                        'password.required' =>
                            'Please enter your password.',

                        'locale.in' =>
                            'The selected language is invalid.',
                    ]
                );


            /*
            |--------------------------------------------------------------------------
            | VALIDATION
            |--------------------------------------------------------------------------
            */

            if ($validator->fails()) {

                return response()->json([
                    'success' =>
                        false,

                    'message' =>
                        $validator
                            ->errors()
                            ->first(),

                    'errors' =>
                        $validator
                            ->errors(),
                ], 422);
            }


            $validated =
                $validator->validated();


            /*
            |--------------------------------------------------------------------------
            | NORMALIZE PHONE
            |--------------------------------------------------------------------------
            */

            $phone =
                $this->normalizePhone(
                    $validated['phone']
                );


            /*
            |--------------------------------------------------------------------------
            | FIND CUSTOMER ACCOUNT
            |--------------------------------------------------------------------------
            */

            $customerAccount =
                CustomerAccount::query()
                    ->where(
                        'phone',
                        $phone
                    )
                    ->first();


            /*
            |--------------------------------------------------------------------------
            | CHECK PASSWORD
            |--------------------------------------------------------------------------
            */

            if (
                !$customerAccount
                ||
                !Hash::check(
                    $validated['password'],
                    $customerAccount->password
                )
            ) {

                return response()->json([
                    'success' =>
                        false,

                    'message' =>
                        'The phone number or password is incorrect.',
                ], 422);
            }


            /*
            |--------------------------------------------------------------------------
            | MUST CHANGE INITIAL PASSWORD
            |--------------------------------------------------------------------------
            |
            | true uniquement si :
            |
            | - le compte possède un mot de passe initial enregistré
            | - le customer n'a jamais choisi son propre mot de passe
            |
            */

            $mustChangePassword =
                !empty(
                    $customerAccount
                        ->initial_password_encrypted
                )
                &&
                is_null(
                    $customerAccount
                        ->password_changed_at
                );


            /*
            |--------------------------------------------------------------------------
            | GENERATE ACCESS TOKEN
            |--------------------------------------------------------------------------
            */

            $plainToken =
                Str::random(
                    64
                );


            $customerAccount
                ->forceFill([
                    'access_token_hash' =>
                        hash(
                            'sha256',
                            $plainToken
                        ),

                    'access_token_expires_at' =>
                        now()
                            ->addHours(
                                2
                            ),

                    'last_login_at' =>
                        now(),

                    /*
                     * Première activité de la session.
                     */
                    'last_app_activity_at' =>
                        now(),

                    /*
                     * Compte uniquement les connexions
                     * réussies.
                     */
                    'login_count' =>
                        ((int) $customerAccount->login_count)
                        + 1,
                ])
                ->save();


            /*
            |--------------------------------------------------------------------------
            | RESPONSE
            |--------------------------------------------------------------------------
            */

            return response()->json([
                'success' =>
                    true,

                'message' =>
                    'You have signed in successfully.',

                'access_token' =>
                    $plainToken,

                'expires_at' =>
                    optional(
                        $customerAccount
                            ->access_token_expires_at
                    )
                        ->toIso8601String(),

                'locale' =>
                    $validated['locale']
                    ?? 'en',

                /*
                 * IMPORTANT :
                 * utilisé par login.tsx
                 */
                'must_change_password' =>
                    $mustChangePassword,

            ], 200);


        } catch (
            Throwable $exception
        ) {

            report(
                $exception
            );


            return response()->json([
                'success' =>
                    false,

                'message' =>
                    'An internal server error occurred during sign in.',
            ], 500);
        }
    }


    /**
     * Déconnexion du client.
     */
    public function logout(
        Request $request
    ): JsonResponse {
        try {

            $plainToken =
                trim(
                    (string)
                    $request->input(
                        'access_token',
                        ''
                    )
                );


            if (
                $plainToken !== ''
            ) {

                CustomerAccount::query()
                    ->where(
                        'access_token_hash',
                        hash(
                            'sha256',
                            $plainToken
                        )
                    )
                    ->update([
                        'access_token_hash' =>
                            null,

                        'access_token_expires_at' =>
                            null,
                    ]);
            }


            return response()->json([
                'success' =>
                    true,

                'message' =>
                    'You have been logged out successfully.',
            ], 200);


        } catch (
            Throwable $exception
        ) {

            report(
                $exception
            );


            return response()->json([
                'success' =>
                    true,

                'message' =>
                    'You have been logged out.',
            ], 200);
        }
    }


    /**
     * Change le mot de passe initial.
     *
     * Le customer doit être connecté avec un access_token valide.
     */
    public function changePassword(
        Request $request
    ): JsonResponse {
        try {

            /*
            |--------------------------------------------------------------------------
            | VALIDATION
            |--------------------------------------------------------------------------
            */

            $validator =
                Validator::make(
                    $request->all(),
                    [
                        'access_token' => [
                            'required',
                            'string',
                        ],

                        'password' => [
                            'required',
                            'string',
                            'min:6',
                            'confirmed',
                            'max:255',
                        ],
                    ],
                    [
                        'access_token.required' =>
                            'Access token is required.',

                        'password.required' =>
                            'Please enter a new password.',

                        'password.min' =>
                            'Password must contain at least 6 characters.',

                        'password.confirmed' =>
                            'Password confirmation does not match.',
                    ]
                );


            if (
                $validator->fails()
            ) {

                return response()->json([
                    'success' =>
                        false,

                    'message' =>
                        $validator
                            ->errors()
                            ->first(),

                    'errors' =>
                        $validator
                            ->errors(),
                ], 422);
            }


            $validated =
                $validator->validated();


            /*
            |--------------------------------------------------------------------------
            | FIND CUSTOMER FROM TOKEN
            |--------------------------------------------------------------------------
            */

            $customerAccount =
                CustomerAccount::query()
                    ->where(
                        'access_token_hash',
                        hash(
                            'sha256',
                            $validated[
                                'access_token'
                            ]
                        )
                    )
                    ->where(
                        function (
                            $query
                        ) {

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


            if (
                !$customerAccount
            ) {

                return response()->json([
                    'success' =>
                        false,

                    'message' =>
                        'Invalid or expired session.',
                ], 401);
            }


            /*
            |--------------------------------------------------------------------------
            | SAVE PERSONAL PASSWORD
            |--------------------------------------------------------------------------
            |
            | CustomerAccount utilise déjà :
            |
            | 'password' => 'hashed'
            |
            | donc pas besoin de Hash::make().
            |
            */

            $customerAccount->password =
                $validated[
                    'password'
                ];


            /*
             * Le customer utilise maintenant
             * son propre mot de passe.
             */

            $customerAccount
                ->password_changed_at =
                    now();


            /*
             * Le mot de passe initial n'est
             * désormais plus nécessaire.
             */

            $customerAccount
                ->initial_password_encrypted =
                    null;


            $customerAccount->save();


            /*
            |--------------------------------------------------------------------------
            | RESPONSE
            |--------------------------------------------------------------------------
            */

            return response()->json([
                'success' =>
                    true,

                'message' =>
                    'Password changed successfully.',

                'must_change_password' =>
                    false,

            ], 200);


        } catch (
            Throwable $exception
        ) {

            report(
                $exception
            );


            return response()->json([
                'success' =>
                    false,

                'message' =>
                    'Unable to change password.',
            ], 500);
        }
    }


    /**
     * Enregistre l'activité du customer dans l'application.
     *
     * L'application mobile envoie périodiquement un heartbeat,
     * uniquement pendant qu'elle est active.
     *
     * Exemple :
     *
     * POST /api/mobile/activity
     *
     * {
     *     "access_token": "...",
     *     "seconds": 60
     * }
     */
    public function activity(
        Request $request
    ): JsonResponse {
        try {

            /*
            |--------------------------------------------------------------------------
            | VALIDATION
            |--------------------------------------------------------------------------
            */

            $validator =
                Validator::make(
                    $request->all(),
                    [
                        'access_token' => [
                            'required',
                            'string',
                        ],

                        'seconds' => [
                            'nullable',
                            'integer',
                            'min:1',
                            'max:300',
                        ],
                    ],
                    [
                        'access_token.required' =>
                            'Access token is required.',

                        'seconds.integer' =>
                            'Activity duration must be an integer.',

                        'seconds.min' =>
                            'Activity duration must be at least 1 second.',

                        'seconds.max' =>
                            'Activity duration cannot exceed 300 seconds.',
                    ]
                );


            if (
                $validator->fails()
            ) {

                return response()->json([
                    'success' =>
                        false,

                    'message' =>
                        $validator
                            ->errors()
                            ->first(),

                    'errors' =>
                        $validator
                            ->errors(),
                ], 422);
            }


            $validated =
                $validator->validated();


            /*
            |--------------------------------------------------------------------------
            | FIND CUSTOMER FROM ACCESS TOKEN
            |--------------------------------------------------------------------------
            */

            $customerAccount =
                CustomerAccount::query()
                    ->where(
                        'access_token_hash',
                        hash(
                            'sha256',
                            $validated[
                                'access_token'
                            ]
                        )
                    )
                    ->where(
                        function (
                            $query
                        ) {

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


            if (
                !$customerAccount
            ) {

                return response()->json([
                    'success' =>
                        false,

                    'message' =>
                        'Invalid or expired session.',
                ], 401);
            }


            /*
            |--------------------------------------------------------------------------
            | HEARTBEAT DURATION
            |--------------------------------------------------------------------------
            |
            | Valeur recommandée côté app : 60 secondes.
            |
            | Le serveur limite quand même la valeur
            | entre 1 et 300 secondes.
            |
            */

            $seconds =
                (int) (
                    $validated[
                        'seconds'
                    ]
                    ?? 60
                );


            $seconds =
                min(
                    max(
                        $seconds,
                        1
                    ),
                    300
                );


            /*
            |--------------------------------------------------------------------------
            | UPDATE ACTIVITY
            |--------------------------------------------------------------------------
            */

            $customerAccount
                ->increment(
                    'total_app_seconds',
                    $seconds
                );


            $customerAccount
                ->forceFill([
                    'last_app_activity_at' =>
                        now(),
                ])
                ->save();


            /*
            |--------------------------------------------------------------------------
            | RESPONSE
            |--------------------------------------------------------------------------
            */

            $freshCustomerAccount =
                $customerAccount->fresh();


            return response()->json([
                'success' =>
                    true,

                'added_seconds' =>
                    $seconds,

                'total_app_seconds' =>
                    (int) (
                        $freshCustomerAccount
                            ->total_app_seconds
                        ?? 0
                    ),

                'last_app_activity_at' =>
                    optional(
                        $freshCustomerAccount
                            ->last_app_activity_at
                    )
                        ->toIso8601String(),

            ], 200);


        } catch (
            Throwable $exception
        ) {

            report(
                $exception
            );


            return response()->json([
                'success' =>
                    false,

                'message' =>
                    'Unable to register app activity.',
            ], 500);
        }
    }


    /**
     * Normalise le numéro de téléphone.
     *
     * Exemple :
     * +965 5555 5555 devient 96555555555
     */
    private function normalizePhone(
        ?string $phone
    ): string {

        return preg_replace(
            '/[^0-9]/',
            '',
            (string) $phone
        ) ?? '';
    }
}