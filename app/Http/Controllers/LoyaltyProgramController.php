<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\LoyaltyPointTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoyaltyProgramController extends Controller
{
    public function index()
    {
        $subscriptions = Subscription::with(['loyaltyPointTransactions'])
            ->orderBy('subscriber_first_name')
            ->get();

        return view('loyalty.index', compact('subscriptions'));
    }

    public function history(Subscription $subscription)
    {
        $subscription->load([
            'loyaltyPointTransactions' => function ($query) {
                $query->with('creator')->latest();
            }
        ]);

        return response()->json([
            'subscription' => [
                'id' => $subscription->id,
                'code' => $subscription->code,
                'name' => trim($subscription->subscriber_first_name . ' ' . $subscription->subscriber_last_name),
                'valid_points' => $subscription->valid_loyalty_points,
            ],
            'transactions' => $subscription->loyaltyPointTransactions->map(function ($transaction) {
                return [
                    'id' => $transaction->id,
                    'points' => $transaction->points,
                    'label' => $transaction->label,
                    'expires_at' => optional($transaction->expires_at)->format('Y-m-d'),
                    'created_at' => optional($transaction->created_at)->format('Y-m-d H:i'),
                    'created_by' => optional($transaction->creator)->first_name,
                    'is_expired' => $transaction->isExpired(),
                ];
            }),
        ]);
    }

    public function storePoints(Request $request, Subscription $subscription)
    {
        $validated = $request->validate([
            'points' => 'required|integer|min:1',
            'label' => 'required|string|max:255',
            'expires_at' => 'nullable|date|after_or_equal:today',
        ]);

        LoyaltyPointTransaction::create([
            'subscription_id' => $subscription->id,
            'points' => $validated['points'],
            'label' => $validated['label'],
            'expires_at' => $validated['expires_at'] ?? null,
            'created_by' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Points added successfully.',
            'valid_points' => $subscription->fresh()->valid_loyalty_points,
        ]);
    }
}
