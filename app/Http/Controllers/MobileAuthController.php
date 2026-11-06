<?php

namespace App\Http\Controllers;

use App\Models\CustomerAccount;
use App\Models\CustomerAppActivity;
use App\Models\Subscription;
use App\Services\OtpException;
use App\Services\OtpService;
use App\Support\CustomerSession;
use App\Support\KuwaitPhone;
use App\Support\SubscriptionCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sign in / sign up with a WhatsApp code only (no password).
 *
 *  1. POST /api/mobile/auth/send-code   { phone, lang }
 *       -> account_status: "account" | "legacy" | "new"
 *          requires_name : true for a new customer
 *
 *  2. POST /api/mobile/auth/verify      { phone, code, full_name?, lang }
 *       -> access_token (no expiry), is_new_customer
 *
 * "legacy" = phone of an existing subscription (back-office / Shopify)
 * without app account: the account is created and linked to it.
 *
 * The password columns are kept (random password for new accounts),
 * /login with password stays available but is not used by the app.
 *
 * APPLE / GOOGLE REVIEW ACCOUNT (config services.app_review, .env):
 * one phone number signs in with a fixed code, without WhatsApp, so the
 * store reviewers can test the app. Only this number, only when
 * APP_REVIEW_ENABLED=true, max 10 wrong codes per hour.
 */
class MobileAuthController extends Controller
{
    public function __construct(private OtpService $otp)
    {
    }

    /*
    |--------------------------------------------------------------------------
    | 1. SEND THE CODE
    |--------------------------------------------------------------------------
    */

    public function sendCode(Request $request): JsonResponse
    {
        $lang = $this->lang($request);
        $phone = $this->normalizePhone($request->input('phone'));

        if (strlen($phone) < 8 || strlen($phone) > 20) {
            return $this->error(
                $lang === 'ar' ? 'يرجى إدخال رقم هاتف صحيح.' : 'Please enter a valid phone number.',
                422
            );
        }

        // Store review account: no WhatsApp message, fixed code
        if ($this->isReviewPhone($phone)) {
            $status = $this->accountStatus($phone);

            Log::info('App review account: code requested', ['ip' => $request->ip()]);

            return response()->json([
                'success' => true,
                'message' => $lang === 'ar'
                    ? 'تم إرسال رمز التحقق إلى واتساب.'
                    : 'A verification code has been sent to your WhatsApp.',
                'account_status' => $status,
                'requires_name' => $status === 'new',
                'expires_at' => now()->addMinutes(30)->toIso8601String(),
                'resend_in' => 30,
            ]);
        }

        if (!OtpService::enabled()) {
            return $this->error(
                $lang === 'ar'
                    ? 'تسجيل الدخول غير متاح حالياً. يرجى التواصل مع الدعم.'
                    : 'Sign in is not available right now. Please contact our support team.',
                503
            );
        }

        $status = $this->accountStatus($phone);

        try {
            $otp = $this->otp->send($phone, OtpService::PURPOSE_LOGIN, $lang, $request->ip());
        } catch (OtpException $exception) {
            return response()->json(array_merge([
                'success' => false,
                'reason' => $exception->reason,
                'message' => $exception->getMessage(),
                'account_status' => $status,
                'requires_name' => $status === 'new',
            ], $exception->extra), $exception->status);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error($lang === 'ar' ? 'حدث خطأ. حاول مرة أخرى.' : 'Something went wrong. Please try again.', 500);
        }

        return response()->json([
            'success' => true,
            'message' => $lang === 'ar'
                ? 'تم إرسال رمز التحقق إلى واتساب.'
                : 'A verification code has been sent to your WhatsApp.',
            'account_status' => $status,
            'requires_name' => $status === 'new',
            'expires_at' => $otp->expires_at?->toIso8601String(),
            'resend_in' => OtpService::resendSeconds(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 2. CHECK THE CODE -> SIGN IN (OR CREATE THE ACCOUNT)
    |--------------------------------------------------------------------------
    */

    public function verify(Request $request): JsonResponse
    {
        $lang = $this->lang($request);
        $phone = $this->normalizePhone($request->input('phone'));

        $validator = Validator::make($request->all(), [
            'code' => ['required', 'string', 'max:10'],
            'full_name' => ['nullable', 'string', 'max:150'],
        ]);

        if ($validator->fails() || strlen($phone) < 8) {
            return $this->error(
                $lang === 'ar' ? 'يرجى إدخال رقم الهاتف والرمز.' : 'Please enter your phone number and the code.',
                422
            );
        }

        $isReview = $this->isReviewPhone($phone);

        if (!$isReview && !OtpService::enabled()) {
            return $this->error(
                $lang === 'ar' ? 'تسجيل الدخول غير متاح حالياً.' : 'Sign in is not available right now.',
                503
            );
        }

        $status = $this->accountStatus($phone);
        $fullName = trim(preg_replace('/\s+/', ' ', (string) $request->input('full_name')));

        // New customer: the name is checked BEFORE using the code
        if ($status === 'new' && mb_strlen($fullName) < 2) {
            return response()->json([
                'success' => false,
                'requires_name' => true,
                'message' => $lang === 'ar' ? 'يرجى إدخال اسمك الكامل.' : 'Please enter your full name.',
            ], 422);
        }

        try {
            if ($isReview) {
                $this->verifyReviewCode((string) $request->input('code'), $lang);
            } else {
                $this->otp->verify($phone, OtpService::PURPOSE_LOGIN, $request->input('code'), $lang);
            }
        } catch (OtpException $exception) {
            return response()->json(array_merge([
                'success' => false,
                'otp_error' => true,
                'reason' => $exception->reason,
                'message' => $exception->getMessage(),
            ], $exception->extra), 422);
        }

        try {
            [$customer, $isNew] = DB::transaction(function () use ($phone, $status, $fullName, $lang) {
                return match ($status) {
                    'account' => [$this->accountByPhone($phone, true) ?? throw new \RuntimeException('Account not found.'), false],
                    'legacy' => [$this->createAccountForSubscription($this->subscriptionByPhone($phone), $phone, $lang), false],
                    default => [$this->createCustomer($phone, $fullName, $lang), true],
                };
            });

            $token = CustomerSession::issue($customer);

            $this->recordActivity($customer, $isNew ? 'app_signup' : 'app_login', ['method' => $isReview ? 'app_review_code' : 'whatsapp_otp']);

            $subscription = $customer->subscription;

            return response()->json([
                'success' => true,
                'message' => $isNew
                    ? ($lang === 'ar' ? 'مرحباً بك! تم إنشاء حسابك.' : 'Welcome! Your account has been created.')
                    : ($lang === 'ar' ? 'تم تسجيل الدخول بنجاح.' : 'You have signed in successfully.'),
                'access_token' => $token,
                // far-future date (10 years): signed in until logout
                'expires_at' => optional($customer->fresh()->access_token_expires_at)->toIso8601String(),
                'must_change_password' => false,
                'is_new_customer' => $isNew,
                'locale' => $lang,
                'customer' => [
                    'id' => $customer->id,
                    'phone' => $customer->phone,
                    'first_name' => $subscription?->subscriber_first_name,
                    'last_name' => $subscription?->subscriber_last_name,
                ],
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error(
                $lang === 'ar' ? 'تعذر تسجيل الدخول. حاول مرة أخرى.' : 'Unable to sign in. Please try again.',
                500
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | ACCOUNTS
    |--------------------------------------------------------------------------
    */

    /**
     * "account" = app account, "legacy" = subscription without app account, "new".
     */
    private function accountStatus(string $phone): string
    {
        if ($this->accountByPhone($phone)) {
            return 'account';
        }

        return $this->subscriptionByPhone($phone) ? 'legacy' : 'new';
    }

    /**
     * App account with the same number. Kuwaiti numbers are compared on
     * their 8 local digits: "50000000" = "96550000000" = "+965 5000 0000"
     * (accounts created from Shopify orders are saved as 965XXXXXXXX).
     */
    private function accountByPhone(string $phone, bool $lock = false): ?CustomerAccount
    {
        $query = KuwaitPhone::whereSame(CustomerAccount::query(), 'phone', $phone)->orderByDesc('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function subscriptionByPhone(string $phone): ?Subscription
    {
        return KuwaitPhone::whereSame(Subscription::query(), 'subscriber_phone', $phone)
            ->whereDoesntHave('customerAccount')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * New customer: subscription (code without province until the first
     * address: C00xxxxx-APP) + customer account.
     */
    private function createCustomer(string $phone, string $fullName, string $lang): CustomerAccount
    {
        [$firstName, $lastName] = $this->splitName($fullName);

        $subscription = Subscription::create([
            'code' => SubscriptionCode::next(null, true),
            'creation_date' => now()->toDateString(),
            'subscriber_first_name' => $firstName,
            'subscriber_last_name' => $lastName,
            'subscriber_phone' => $phone,
            'subscriber_address' => '',
            'subscriber_note' => null,
            'subscriber_province' => '',
            'subscriber_zone' => '',
            'subscriber_delivery_slot' => null,
            'delivery_days' => [],
            'day_recipes' => [],
        ]);

        return $this->createAccountForSubscription($subscription, $phone, $lang);
    }

    /**
     * App account linked to a subscription.
     * Random password (never shown): the password columns stay filled
     * so the password sign in can be enabled again later.
     */
    private function createAccountForSubscription(?Subscription $subscription, string $phone, string $lang): CustomerAccount
    {
        if (!$subscription) {
            throw new \RuntimeException('Subscription not found for phone.');
        }

        return CustomerAccount::create([
            'subscription_id' => $subscription->id,
            'phone' => $phone,
            'email' => null,
            'password' => Str::random(40), // hashed by the model cast
            'settings' => ['language' => $lang],
            'initial_password_encrypted' => null,
            'password_changed_at' => null,
        ]);
    }

    /**
     * "Sara Al Ahmad" -> ["Sara", "Al Ahmad"]
     */
    private function splitName(string $fullName): array
    {
        $parts = explode(' ', $fullName, 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    /*
    |--------------------------------------------------------------------------
    | APPLE / GOOGLE REVIEW ACCOUNT
    |--------------------------------------------------------------------------
    */

    /** Phone of the store review account (compared on the 8 local digits). */
    private function isReviewPhone(string $phone): bool
    {
        if (!config('services.app_review.enabled')) {
            return false;
        }

        $reviewPhone = $this->normalizePhone(config('services.app_review.phone'));
        $code = (string) config('services.app_review.code');

        if (strlen($reviewPhone) < 8 || strlen($code) < 4 || strlen($phone) < 8) {
            return false;
        }

        return substr($phone, -8) === substr($reviewPhone, -8);
    }

    /** Fixed code of the review account (max 10 wrong codes per hour). */
    private function verifyReviewCode(string $code, string $lang): void
    {
        $key = 'app-review-code';

        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw new OtpException(
                'too_many_attempts',
                $lang === 'ar' ? 'محاولات كثيرة. حاول لاحقاً.' : 'Too many attempts. Please try again later.',
                429
            );
        }

        if (!hash_equals((string) config('services.app_review.code'), trim($code))) {
            RateLimiter::hit($key, 3600);

            throw new OtpException(
                'invalid_code',
                $lang === 'ar' ? 'الرمز غير صحيح.' : 'The code is not correct.',
                422
            );
        }

        RateLimiter::clear($key);
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    private function recordActivity(CustomerAccount $customer, string $code, array $metadata = []): void
    {
        try {
            if (class_exists(CustomerAppActivity::class)) {
                CustomerAppActivity::record($customer->id, $code, $metadata);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function normalizePhone(?string $phone): string
    {
        return preg_replace('/[^0-9]/', '', (string) $phone) ?? '';
    }

    private function lang(Request $request): string
    {
        return $request->input('lang', $request->input('locale')) === 'ar' ? 'ar' : 'en';
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }
}
