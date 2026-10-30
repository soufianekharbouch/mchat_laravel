<?php

namespace App\Http\Controllers;

use App\Models\CustomerAccount;
use App\Models\Subscription;
use App\Services\OtpException;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * WhatsApp OTP (Zoko) for the mobile app.
 *
 * POST /api/mobile/otp/send   { phone, purpose: "register" | "reset_password", lang }
 *
 * The code is checked by the action that needs it:
 *   register        -> MobileRegisterController ("otp_code")
 *   reset_password  -> MobilePasswordResetController::verifyOtp
 */
class MobileOtpController extends Controller
{
    public function __construct(private OtpService $otp)
    {
    }

    public function send(Request $request): JsonResponse
    {
        $lang = $request->input('lang') === 'ar' ? 'ar' : 'en';

        $request->merge([
            'phone' => preg_replace('/[^0-9]/', '', (string) $request->input('phone')) ?? '',
        ]);

        $validator = Validator::make($request->all(), [
            'phone' => ['required', 'string', 'min:8', 'max:20'],
            'purpose' => ['required', Rule::in(OtpService::PURPOSES)],
        ], [
            'phone.required' => $lang === 'ar' ? 'يرجى إدخال رقم الهاتف.' : 'Please enter your phone number.',
            'phone.min' => $lang === 'ar' ? 'يرجى إدخال رقم هاتف صحيح.' : 'Please enter a valid phone number.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $phone = (string) $request->input('phone');
        $purpose = (string) $request->input('purpose');

        // OTP disabled in .env: the app continues without a code
        if (! OtpService::enabled()) {
            // A password can never be reset without a code
            if ($purpose === OtpService::PURPOSE_RESET_PASSWORD) {
                return response()->json([
                    'success' => false,
                    'message' => $lang === 'ar'
                        ? 'هذه الخدمة غير متاحة حالياً. يرجى التواصل مع الدعم.'
                        : 'This service is not available right now. Please contact our support team.',
                ], 503);
            }

            return response()->json([
                'success' => true,
                'otp_required' => false,
            ]);
        }

        /*
         * Register: do not send a code to a number that can't register
         * (same checks as MobileRegisterController).
         */
        if ($purpose === OtpService::PURPOSE_REGISTER && $this->phoneAlreadyUsed($phone)) {
            return response()->json([
                'success' => false,
                'message' => $lang === 'ar'
                    ? 'يوجد حساب مرتبط بهذا الرقم. يرجى تسجيل الدخول أو التواصل مع الدعم.'
                    : 'An account already exists with this phone number. Please sign in or contact our support team.',
                'errors' => ['phone' => ['phone_taken']],
            ], 422);
        }

        /*
         * Forgot password: the number must belong to an app account.
         */
        if (
            $purpose === OtpService::PURPOSE_RESET_PASSWORD
            && ! CustomerAccount::where('phone', $phone)->exists()
        ) {
            return response()->json([
                'success' => false,
                'message' => $lang === 'ar'
                    ? 'لا يوجد حساب مرتبط بهذا الرقم.'
                    : 'No account was found with this phone number.',
                'errors' => ['phone' => ['phone_not_found']],
            ], 422);
        }

        try {
            $otp = $this->otp->send($phone, $purpose, $lang, $request->ip());

            return response()->json([
                'success' => true,
                'otp_required' => true,
                'message' => $lang === 'ar'
                    ? 'تم إرسال رمز التحقق إلى واتساب.'
                    : 'A verification code has been sent to your WhatsApp.',
                'expires_at' => $otp->expires_at?->toIso8601String(),
                'expires_in' => OtpService::ttlMinutes() * 60,
                'resend_in' => OtpService::resendSeconds(),
            ]);
        } catch (OtpException $exception) {
            return response()->json(array_merge([
                'success' => false,
                'reason' => $exception->reason,
                'message' => $exception->getMessage(),
            ], $exception->extra), $exception->status);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => $lang === 'ar' ? 'حدث خطأ. حاول مرة أخرى.' : 'Something went wrong. Please try again.',
            ], 500);
        }
    }

    private function phoneAlreadyUsed(string $phone): bool
    {
        if (CustomerAccount::where('phone', $phone)->exists()) {
            return true;
        }

        return Subscription::query()
            ->whereRaw(
                "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(subscriber_phone, '+', ''), ' ', ''), '-', ''), '(', ''), ')', '') = ?",
                [$phone]
            )
            ->exists();
    }
}
