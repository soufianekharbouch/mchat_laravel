<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Sends WhatsApp messages through the Zoko API.
 *
 * POST {base_url}/message   (header: apikey)
 * Docs: https://docs.zoko.io/messaging-api/sending-a-message/
 */
class ZokoService
{
    /**
     * Sends the OTP template and returns the Zoko message id.
     *
     * Authentication templates with a "Copy code" button need the code
     * twice (body + button). Zoko sends them as "buttonTemplate";
     * a plain template only needs it once. The types listed in
     * ZOKO_OTP_TYPES are tried in order until Zoko accepts one.
     *
     * @throws RuntimeException
     */
    public function sendOtp(string $recipient, string $code): ?string
    {
        $types = array_filter(array_map(
            'trim',
            explode(',', (string) config('services.zoko.otp_types', 'buttonTemplate,template'))
        ));

        $lastError = 'No OTP template type configured.';

        foreach ($types as $type) {
            $args = $type === 'template'
                ? [$code]
                : [$code, $code];

            try {
                return $this->sendTemplate(
                    $recipient,
                    (string) config('services.zoko.otp_template_id'),
                    $args,
                    $type,
                    (string) config('services.zoko.otp_language', 'ar')
                );
            } catch (Throwable $exception) {
                $lastError = $exception->getMessage();

                Log::warning('Zoko OTP send failed', [
                    'type' => $type,
                    'recipient' => $this->maskPhone($recipient),
                    'error' => $lastError,
                ]);
            }
        }

        throw new RuntimeException($lastError);
    }

    /**
     * Generic template sender.
     *
     * @throws RuntimeException
     */
    public function sendTemplate(
        string $recipient,
        string $templateId,
        array $args = [],
        string $type = 'template',
        ?string $language = null
    ): ?string {
        $apiKey = (string) config('services.zoko.api_key');

        if ($apiKey === '' || $templateId === '') {
            throw new RuntimeException('Zoko is not configured (ZOKO_API_KEY / ZOKO_OTP_TEMPLATE_ID).');
        }

        $payload = [
            'channel' => 'whatsapp',
            'recipient' => $recipient,
            'type' => $type,
            'templateId' => $templateId,
            'templateArgs' => array_values(array_map('strval', $args)),
        ];

        if ($language) {
            $payload['templateLanguage'] = $language;
        }

        $response = Http::withHeaders([
                'apikey' => $apiKey,
            ])
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->post(rtrim((string) config('services.zoko.base_url'), '/') . '/message', $payload);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Zoko HTTP ' . $response->status() . ': ' . mb_substr($response->body(), 0, 500)
            );
        }

        $data = $response->json() ?? [];

        // Zoko answers { status: "200", statusText: "Accepted", messageId: "..." }
        $status = (string) ($data['status'] ?? '');

        if ($status !== '' && ! in_array($status, ['200', '201', '202'], true)) {
            throw new RuntimeException('Zoko rejected the message: ' . mb_substr($response->body(), 0, 500));
        }

        return $data['messageId'] ?? $data['id'] ?? null;
    }

    /**
     * Local Kuwait numbers (8 digits) get the 965 country code.
     * "+965 5000 0000" / "0096550000000" / "50000000" -> "96550000000"
     */
    public static function toWhatsappNumber(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 8) {
            $digits = (string) config('services.zoko.default_country_code', '965') . $digits;
        }

        return $digits;
    }

    private function maskPhone(string $phone): string
    {
        return strlen($phone) > 4
            ? str_repeat('*', strlen($phone) - 4) . substr($phone, -4)
            : $phone;
    }
}
