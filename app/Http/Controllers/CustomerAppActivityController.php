<?php

namespace App\Http\Controllers;

use App\Models\CustomerAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerAppActivityController extends Controller
{
    public function index(
        Request $request
    ): JsonResponse {

        $accessToken =
            trim(
                (string) $request->input(
                    'access_token',
                    ''
                )
            );


        if ($accessToken === '') {

            return response()->json([
                'success' => false,
                'message' => 'Access token is required.',
            ], 401);
        }


        $customerAccount =
            CustomerAccount::query()
                ->where(
                    'access_token_hash',
                    hash(
                        'sha256',
                        $accessToken
                    )
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


        if (!$customerAccount) {

            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired session.',
            ], 401);
        }


        $activities =
            $customerAccount
                ->appActivities()
                ->latest('created_at')
                ->limit(100)
                ->get()
                ->map(
                    function ($activity) {

                        return [
                            'id' =>
                                $activity->id,

                            'code' =>
                                $activity->code,

                            'label' =>
                                $activity->label,

                            'metadata' =>
                                $activity->metadata,

                            'created_at' =>
                                optional(
                                    $activity->created_at
                                )->toIso8601String(),
                        ];
                    }
                );


        return response()->json([
            'success' => true,

            'activities' =>
                $activities,
        ]);
    }
}