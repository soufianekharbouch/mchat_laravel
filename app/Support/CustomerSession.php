<?php

namespace App\Support;

use App\Models\CustomerAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Mobile app session (access token).
 *
 * Same storage as CustomerAuthController::login():
 *   customer_accounts.access_token_hash = sha256(token)
 * WhatsApp session = "unlimited": the expiry date is set far in the
 * future (10 years) instead of NULL, so the old checks of the app and of
 * the other controllers (access_token_expires_at > now) accept it.
 * The customer stays signed in until he taps "Logout".
 */
class CustomerSession
{
    public const SESSION_YEARS = 10;

    /**
     * Creates a new token for the customer and returns it (plain).
     */
    public static function issue(CustomerAccount $customer): string
    {
        $plainToken = Str::random(64);

        $customer->forceFill([
            'access_token_hash' => hash('sha256', $plainToken),
            'access_token_expires_at' => now()->addYears(self::SESSION_YEARS),
            'last_login_at' => now(),
            'last_app_activity_at' => now(),
            'login_count' => ((int) $customer->login_count) + 1,
        ])->save();

        return $plainToken;
    }

    /**
     * Customer of the request (Bearer token or "access_token" field),
     * null for a visitor or an expired session.
     */
    public static function fromRequest(Request $request): ?CustomerAccount
    {
        $token = $request->bearerToken() ?? $request->input('access_token');

        if (!$token || !is_string($token)) {
            return null;
        }

        $customer = CustomerAccount::where('access_token_hash', hash('sha256', $token))->first();

        if (!$customer || !$customer->hasValidAccessToken()) {
            return null;
        }

        return $customer;
    }
}
