<?php

namespace App\Http\Controllers;

use App\Models\CustomerAccount;
use App\Services\OtpException;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/**
 * Forgot password (mobile app) with a WhatsApp code.
 *
 *  1. POST /api/mobile/otp/send                 { phone, purpose: "reset_password" }
 *  2. POST /api/mobile/password/verify-otp      { phone, code }        -> reset_token (15 min)
 *  3. POST /api/mobile/password/reset           { phone, reset_token, password, password_confirmation }
 *
 * After step 3 the customer signs in with the new password.
 */
class MobilePasswordResetController extends Controller
{
    private const TOKEN_MINUTES = 15;

    public function __construct(private OtpService $otp)
    {
    }

    /*
    |--------------------------------------------------------------------------
    | STEP 2 — CHECK THE WHATSAPP CODE
    |--------------------------------------------------------------------------
    */

    public function verifyOtp(Request $request): JsonResponse
    {
        $lang = $this->lang($request);
        $phone = $this->normalizePhone($request->input('phone'));

        if ($phone === '' || ! CustomerAccount::where('phone', $phone)->exists()) {
            return $this->error(
                $lang === 'ar' ? 'لا يوجد حساب مرتبط بهذا الرقم.' : 'No account was found with this phone number.',
                422
            );
        }

        try {
            $this->otp->verify($phone, OtpService::PURPOSE_RESET_PASSWORD, $request->input('code'), $lang);
        } catch (OtpException $exception) {
            return response()->json(array_merge([
                'success' => false,
                'otp_error' => true,
                'reason' => $exception->reason,
                'message' => $exception->getMessage(),
            ], $exception->extra), 422);
        }

        /*
         * One-time reset token, kept 15 minutes.
         * Only its hash is stored in the cache.
         */
        $token = Str::random(64);

        Cache::put(
            $this->cacheKey($token),
            $phone,
            now()->addMinutes(self::TOKEN_MINUTES)
        );

        return response()->json([
            'success' => true,
            'reset_token' => $token,
            'expires_in' => self::TOKEN_MINUTES * 60,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | STEP 3 — SAVE THE NEW PASSWORD
    |--------------------------------------------------------------------------
    */

    public function reset(Request $request): JsonResponse
    {
        $lang = $this->lang($request);

        $request->merge(['phone' => $this->normalizePhone($request->input('phone'))]);

        $validator = Validator::make($request->all(), [
            'phone' => ['required', 'string'],
            'reset_token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ], [
            'password.required' => $lang === 'ar' ? 'يرجى إدخال كلمة المرور الجديدة.' : 'Please enter the new password.',
            'password.min' => $lang === 'ar' ? 'يجب أن تتكون كلمة المرور من 8 أحرف على الأقل.' : 'Password must be at least 8 characters.',
            'password.confirmed' => $lang === 'ar' ? 'كلمتا المرور غير متطابقتين.' : 'Passwords do not match.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $key = $this->cacheKey((string) $request->input('reset_token'));
        $phone = (string) $request->input('phone');

        if (Cache::get($key) !== $phone) {
            return response()->json([
                'success' => false,
                'token_expired' => true,
                'message' => $lang === 'ar'
                    ? 'انتهت صلاحية الطلب. يرجى طلب رمز جديد.'
                    : 'This request has expired. Please request a new code.',
            ], 422);
        }

        try {
            $account = CustomerAccount::where('phone', $phone)->first();

            if (! $account) {
                return $this->error(
                    $lang === 'ar' ? 'لا يوجد حساب مرتبط بهذا الرقم.' : 'No account was found with this phone number.',
                    422
                );
            }

            $account->password = Hash::make((string) $request->input('password'));

            // The customer chose the password: no forced change after login
            if (Schema::hasColumn($account->getTable(), 'must_change_password')) {
                $account->must_change_password = false;
            }

            // Sign out the other devices (old sessions)
            if (Schema::hasColumn($account->getTable(), 'access_token_hash')) {
                $account->access_token_hash = null;
            }

            $account->save();

            Cache::forget($key);

            return response()->json([
                'success' => true,
                'message' => $lang === 'ar'
                    ? 'تم تغيير كلمة المرور بنجاح. يمكنك تسجيل الدخول الآن.'
                    : 'Your password has been changed. You can sign in now.',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error(
                $lang === 'ar' ? 'تعذر تغيير كلمة المرور. حاول مرة أخرى.' : 'Unable to change the password. Please try again.',
                500
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    private function cacheKey(string $token): string
    {
        return 'mobile_password_reset:' . hash('sha256', $token);
    }

    private function normalizePhone(?string $phone): string
    {
        return preg_replace('/[^0-9]/', '', (string) $phone) ?? '';
    }

    private function lang(Request $request): string
    {
        return in_array($request->input('lang'), ['ar', 'en'], true)
            ? $request->input('lang')
            : 'en';
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
