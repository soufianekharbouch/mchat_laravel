<?php

namespace App\Services\Hesabe;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Hesabe payment gateway (API v2, "indirect" = Hesabe payment page).
 *
 *  - every request: JSON -> AES-256-CBC (secret key 32 chars, IV 16 chars,
 *    block 32 padding) -> hex, header "accessCode"
 *  - checkout()            POST {gateway}/checkout        -> payment page URL
 *  - transactionByReference GET {gateway}/api/transaction/{ref}?isOrderReference=1
 *
 * The result of a payment is ALWAYS read with transactionByReference():
 * the redirect of the customer and the webhook are only a signal
 * (not signed, and a refused card sends nothing at all).
 *
 * Same algorithm as the official kit hesabe-tech-team/api2-kit-php.
 */
class HesabeClient
{
    private const METHOD = 'aes-256-cbc';
    private const BLOCK = 32;

    /** payment_status codes / texts of a paid transaction */
    private const PAID_CODES = [1, 6];
    private const PAID_TEXTS = ['SUCCESSFUL', 'SUCCESS', 'CAPTURED', 'PAID', 'ACCEPT'];

    public static function configured(): bool
    {
        return filled(config('services.hesabe.merchant_code'))
            && filled(config('services.hesabe.access_code'))
            && strlen((string) config('services.hesabe.secret_key')) === 32
            && strlen((string) config('services.hesabe.iv_key')) === 16;
    }

    public function gatewayUrl(): string
    {
        $url = (string) config('services.hesabe.gateway_url');

        if ($url === '') {
            $url = config('services.hesabe.environment') === 'production'
                ? 'https://api.hesabe.com'
                : 'https://sandbox.hesabe.com';
        }

        return rtrim($url, '/');
    }

    /*
    |--------------------------------------------------------------------------
    | CHECKOUT
    |--------------------------------------------------------------------------
    */

    /**
     * @return array{token: string, payment_url: string}
     */
    public function checkout(
        float $amount,
        string $reference,
        string $responseUrl,
        string $failureUrl,
        ?string $webhookUrl = null,
        int $paymentType = 0,
        array $extra = []
    ): array {
        $payload = array_filter([
            'merchantCode' => (string) config('services.hesabe.merchant_code'),
            'amount' => number_format($amount, 3, '.', ''),
            'currency' => 'KWD',
            'paymentType' => $paymentType,
            'orderReferenceNumber' => $reference,
            'responseUrl' => $responseUrl,
            'failureUrl' => $failureUrl,
            'version' => '2.0',
            'webhookUrl' => $webhookUrl,
        ] + $extra, fn ($value) => $value !== null && $value !== '');

        $response = Http::withHeaders($this->headers())
            ->timeout(30)
            ->post($this->gatewayUrl() . '/checkout', ['data' => $this->encrypt($payload)]);

        $envelope = $this->parse($response->body(), $response->status());

        if (($envelope['status'] ?? null) === false || $response->status() >= 300) {
            Log::warning('Hesabe checkout refused', [
                'reference' => $reference,
                'http' => $response->status(),
                'message' => $envelope['message'] ?? null,
                'errors' => $envelope['data'] ?? null,
            ]);

            throw new RuntimeException('Hesabe: ' . ($envelope['message'] ?? 'payment refused (HTTP ' . $response->status() . ')'));
        }

        $data = $envelope['response']['data'] ?? null;

        if (!is_string($data) || $data === '') {
            Log::warning('Hesabe checkout: no payment token', ['reference' => $reference, 'envelope' => $envelope]);

            throw new RuntimeException('Hesabe: no payment page returned.');
        }

        return [
            'token' => $data,
            'payment_url' => $this->gatewayUrl() . '/payment?data=' . str_replace('%2F', '/', rawurlencode($data)),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | RESULT OF A PAYMENT
    |--------------------------------------------------------------------------
    */

    /**
     * Transaction of our order reference at Hesabe.
     * null = nothing there (not paid yet / refused / abandoned).
     */
    public function transactionByReference(string $reference): ?array
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(20)
            // Again only when Hesabe cannot be reached (never on "not found")
            ->retry(2, 400, fn ($exception) => $exception instanceof \Illuminate\Http\Client\ConnectionException, throw: false)
            ->get($this->gatewayUrl() . '/api/transaction/' . rawurlencode($reference), ['isOrderReference' => 1]);

        if ($response->status() === 404) {
            return null;
        }

        $envelope = $this->parse($response->body(), $response->status());

        if (($envelope['status'] ?? null) === false) {
            // "No transaction" answered with status false
            if ($response->status() === 404 || in_array((int) ($envelope['code'] ?? 0), [404], true)) {
                return null;
            }

            throw new RuntimeException('Hesabe lookup: ' . ($envelope['message'] ?? 'error HTTP ' . $response->status()));
        }

        if ($response->status() >= 300) {
            throw new RuntimeException('Hesabe lookup: HTTP ' . $response->status());
        }

        $record = $envelope['response'] ?? $envelope['data'] ?? null;

        // Some answers wrap the transaction once more
        if (is_array($record) && isset($record['data']) && is_array($record['data'])) {
            $record = $record['data'];
        }

        // A list: the last attempt of this reference
        if (is_array($record) && array_is_list($record)) {
            $record = end($record) ?: null;
        }

        return is_array($record) && $record !== [] ? $record : null;
    }

    /** Transaction by its Hesabe token (webhook without our reference). */
    public function transactionByToken(string $token): ?array
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(20)
            ->get($this->gatewayUrl() . '/api/transaction/' . rawurlencode($token));

        if ($response->status() === 404) {
            return null;
        }

        $envelope = $this->parse($response->body(), $response->status());

        if (($envelope['status'] ?? null) === false || $response->status() >= 300) {
            return null;
        }

        $record = $envelope['response'] ?? $envelope['data'] ?? null;

        if (is_array($record) && isset($record['data']) && is_array($record['data'])) {
            $record = $record['data'];
        }

        return is_array($record) && $record !== [] ? $record : null;
    }

    public static function isPaid(?array $record): bool
    {
        if (!$record) {
            return false;
        }

        $code = $record['payment_status'] ?? null;

        if (is_int($code) || (is_string($code) && ctype_digit(trim($code)))) {
            return in_array((int) $code, self::PAID_CODES, true);
        }

        foreach (['status', 'result_code', 'resultCode'] as $key) {
            $value = $record[$key] ?? null;

            if (is_string($value) && in_array(strtoupper(trim($value)), self::PAID_TEXTS, true)) {
                return true;
            }
        }

        return false;
    }

    /** First value found among several possible field names. */
    public static function field(?array $record, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($record[$key]) && is_scalar($record[$key]) && (string) $record[$key] !== '') {
                return (string) $record[$key];
            }
        }

        return null;
    }

    /** "data" of the customer redirect (response / failure URL): decrypted, never trusted alone. */
    public function readRedirect(?string $data): ?array
    {
        if (!$data) {
            return null;
        }

        try {
            $decoded = $this->decrypt($data);
        } catch (\Throwable) {
            return null;
        }

        $body = $decoded['response'] ?? null;

        if (is_array($body) && is_array($body['data'] ?? null)) {
            $body = $body['data'];
        }

        $result = $body ?? $decoded;

        return is_array($result) ? $result : null;
    }

    /*
    |--------------------------------------------------------------------------
    | ENCRYPTION (same as the official kit)
    |--------------------------------------------------------------------------
    */

    public function encrypt(array $payload): string
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $length = self::BLOCK - (strlen($body) % self::BLOCK);

        $cipher = openssl_encrypt(
            $body . str_repeat(chr($length), $length),
            self::METHOD,
            $this->key(),
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            $this->iv()
        );

        if ($cipher === false) {
            throw new RuntimeException('Hesabe: encryption failed.');
        }

        return bin2hex($cipher);
    }

    public function decrypt(string $hex): mixed
    {
        $hex = trim($hex);

        if ($hex === '' || strlen($hex) % 2 !== 0 || !ctype_xdigit($hex)) {
            throw new RuntimeException('Hesabe: not an encrypted answer.');
        }

        $raw = hex2bin($hex);

        $plain = strlen($raw) % 16 === 0
            ? openssl_decrypt($raw, self::METHOD, $this->key(), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $this->iv())
            : false;

        if ($plain === false) {
            throw new RuntimeException('Hesabe: decryption failed (keys?).');
        }

        $length = $plain === '' ? 0 : ord($plain[-1]);
        $unpadded = ($length > 0 && $length <= self::BLOCK && substr($plain, -$length) === str_repeat(chr($length), $length))
            ? substr($plain, 0, -$length)
            : rtrim($plain, "\0");

        return json_decode($unpadded, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    private function headers(): array
    {
        return [
            'accessCode' => (string) config('services.hesabe.access_code'),
            'Accept' => 'application/json',
        ];
    }

    /** Answer of Hesabe: encrypted hex, JSON string of hex, or plain JSON (errors). */
    private function parse(string $raw, int $status): array
    {
        $raw = trim($raw);

        if ($raw !== '' && ctype_xdigit($raw)) {
            $decoded = $this->decrypt($raw);

            return is_array($decoded) ? $decoded : [];
        }

        $json = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);

        if (is_string($json) && ctype_xdigit($json)) {
            $decoded = $this->decrypt($json);

            return is_array($decoded) ? $decoded : [];
        }

        if (is_array($json)) {
            if (is_string($json['response'] ?? null) && ctype_xdigit($json['response'])) {
                $decoded = $this->decrypt($json['response']);

                return is_array($decoded) ? $decoded : [];
            }

            return $json;
        }

        throw new RuntimeException("Hesabe: unreadable answer (HTTP {$status}).");
    }

    private function key(): string
    {
        $key = (string) config('services.hesabe.secret_key');

        if (strlen($key) !== 32) {
            throw new RuntimeException('HESABE_SECRET_KEY must be 32 characters.');
        }

        return $key;
    }

    private function iv(): string
    {
        $iv = (string) config('services.hesabe.iv_key');

        if (strlen($iv) !== 16) {
            throw new RuntimeException('HESABE_IV_KEY must be 16 characters.');
        }

        return $iv;
    }
}
