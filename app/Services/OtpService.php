<?php

namespace App\Services;

use App\Models\OtpCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * WhatsApp one-time codes.
 *
 *   send()   -> generates a 6-digit code, stores its hash, sends it via Zoko
 *   verify() -> checks the code and consumes it (single use)
 *
 * Errors are thrown as OtpException with a translated message and a
 * machine code (cooldown, too_many, send_failed, expired, invalid, ...).
 */
class OtpService
{
    public const PURPOSE_REGISTER = 'register';
    public const PURPOSE_RESET_PASSWORD = 'reset_password';
    public const PURPOSE_LOGIN = 'login'; // WhatsApp sign in / sign up

    public const PURPOSES = [
        self::PURPOSE_LOGIN,
        self::PURPOSE_REGISTER,
        self::PURPOSE_RESET_PASSWORD,
    ];

    public function __construct(private ZokoService $zoko)
    {
    }

    public static function enabled(): bool
    {
        return (bool) config('services.zoko.otp_enabled', false);
    }

    public static function ttlMinutes(): int
    {
        return (int) config('services.zoko.otp_ttl_minutes', 10);
    }

    public static function resendSeconds(): int
    {
        return (int) config('services.zoko.otp_resend_seconds', 60);
    }

    public static function maxPerHour(): int
    {
        return (int) config('services.zoko.otp_max_per_hour', 5);
    }

    public static function maxAttempts(): int
    {
        return (int) config('services.zoko.otp_max_attempts', 5);
    }

    /*
    |--------------------------------------------------------------------------
    | SEND
    |--------------------------------------------------------------------------
    */

    /**
     * @param string $phone normalized phone (digits only, as stored in customer_accounts)
     *
     * @throws OtpException
     */
    public function send(string $phone, string $purpose, string $lang = 'en', ?string $ip = null): OtpCode
    {
        $now = Carbon::now();

        $last = OtpCode::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose)
            ->latest('id')
            ->first();

        // 1 code per minute
        if ($last && $last->created_at && $last->created_at->gt($now->copy()->subSeconds(self::resendSeconds()))) {
            $wait = self::resendSeconds() - $last->created_at->diffInSeconds($now);

            throw new OtpException(
                'cooldown',
                $lang === 'ar'
                    ? "يرجى الانتظار {$wait} ثانية قبل طلب رمز جديد."
                    : "Please wait {$wait} seconds before requesting a new code.",
                429,
                ['retry_after' => max(1, $wait)]
            );
        }

        // Max codes per hour for one phone
        $sentLastHour = OtpCode::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose)
            ->where('created_at', '>=', $now->copy()->subHour())
            ->count();

        if ($sentLastHour >= self::maxPerHour()) {
            throw new OtpException(
                'too_many',
                $lang === 'ar'
                    ? 'لقد طلبت رموزاً كثيرة. حاول مرة أخرى بعد ساعة.'
                    : 'Too many codes requested. Please try again in one hour.',
                429
            );
        }

        $code = (string) random_int(100000, 999999);

        $otp = DB::transaction(function () use ($phone, $purpose, $code, $now, $ip) {

            // Older pending codes for this phone can no longer be used
            OtpCode::query()
                ->where('phone', $phone)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => $now]);

            return OtpCode::create([
                'phone' => $phone,
                'purpose' => $purpose,
                'code_hash' => $this->hash($code),
                'attempts' => 0,
                'expires_at' => $now->copy()->addMinutes(self::ttlMinutes()),
                'channel' => 'whatsapp',
                'ip_address' => $ip,
            ]);
        });

        try {
            $messageId = $this->zoko->sendOtp(ZokoService::toWhatsappNumber($phone), $code);

            $otp->update(['provider_message_id' => $messageId]);
        } catch (Throwable $exception) {
            Log::error('OTP WhatsApp send failed', [
                'otp_id' => $otp->id,
                'error' => $exception->getMessage(),
            ]);

            // Not sent: does not count for the cooldown / hourly limit
            $otp->delete();

            throw new OtpException(
                'send_failed',
                $lang === 'ar'
                    ? 'تعذر إرسال الرمز عبر واتساب. تأكد من رقمك وحاول مرة أخرى.'
                    : 'We could not send the code on WhatsApp. Check your number and try again.',
                502
            );
        }

        return $otp;
    }

    /*
    |--------------------------------------------------------------------------
    | VERIFY
    |--------------------------------------------------------------------------
    */

    /**
     * Checks the code and consumes it.
     *
     * @throws OtpException
     */
    public function verify(string $phone, string $purpose, ?string $code, string $lang = 'en'): void
    {
        $code = preg_replace('/[^0-9]/', '', (string) $code) ?? '';

        if (strlen($code) !== 6) {
            throw new OtpException(
                'invalid',
                $lang === 'ar' ? 'يرجى إدخال الرمز المكوّن من 6 أرقام.' : 'Please enter the 6-digit code.',
                422
            );
        }

        DB::transaction(function () use ($phone, $purpose, $code, $lang) {

            /** @var OtpCode|null $otp */
            $otp = OtpCode::query()
                ->where('phone', $phone)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $otp) {
                throw new OtpException(
                    'not_found',
                    $lang === 'ar' ? 'لا يوجد رمز صالح. اطلب رمزاً جديداً.' : 'No valid code. Please request a new one.',
                    422
                );
            }

            if ($otp->isExpired()) {
                throw new OtpException(
                    'expired',
                    $lang === 'ar' ? 'انتهت صلاحية الرمز. اطلب رمزاً جديداً.' : 'This code has expired. Please request a new one.',
                    422
                );
            }

            if ($otp->attempts >= self::maxAttempts()) {
                throw new OtpException(
                    'locked',
                    $lang === 'ar' ? 'محاولات خاطئة كثيرة. اطلب رمزاً جديداً.' : 'Too many wrong attempts. Please request a new code.',
                    422
                );
            }

            if (! hash_equals($otp->code_hash, $this->hash($code))) {
                $otp->increment('attempts');

                $left = max(0, self::maxAttempts() - $otp->attempts);

                throw new OtpException(
                    'invalid',
                    $lang === 'ar'
                        ? "الرمز غير صحيح. المحاولات المتبقية: {$left}."
                        : "Incorrect code. Attempts left: {$left}.",
                    422,
                    ['attempts_left' => $left]
                );
            }

            $otp->update(['consumed_at' => Carbon::now()]);
        });
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
