<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends pushes with Expo (100 per request).
 *
 * $messages: list of ['device_id' => mobile_devices.id, 'message' => Expo message]
 * Returns one result per message (same order): ['ok' => bool, 'error' => ?string]
 *
 * A phone that uninstalled the app (DeviceNotRegistered) is disabled.
 */
class PushSender
{
    public const EXPO_URL = 'https://exp.host/--/api/v2/push/send';

    public function send(array $messages): array
    {
        $results = [];

        foreach (array_chunk($messages, 100, true) as $chunk) {
            $payload = array_values(array_map(fn ($item) => $item['message'], $chunk));
            $keys = array_keys($chunk);

            try {
                $response = Http::timeout(20)->acceptJson()->post(self::EXPO_URL, $payload);
            } catch (Throwable $exception) {
                foreach ($keys as $key) {
                    $results[$key] = ['ok' => false, 'error' => 'Expo unreachable: ' . mb_substr($exception->getMessage(), 0, 150)];
                }

                Log::warning('Expo push request error', ['error' => $exception->getMessage()]);

                continue;
            }

            if (!$response->successful()) {
                foreach ($keys as $key) {
                    $results[$key] = ['ok' => false, 'error' => 'Expo HTTP ' . $response->status()];
                }

                Log::warning('Expo push request failed', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]);

                continue;
            }

            $tickets = $response->json('data') ?? [];

            // One message sent alone: Expo may answer one ticket (not a list)
            if (isset($tickets['status'])) {
                $tickets = [$tickets];
            }

            foreach ($keys as $index => $key) {
                $ticket = $tickets[$index] ?? null;

                if (is_array($ticket) && ($ticket['status'] ?? null) === 'ok') {
                    $results[$key] = ['ok' => true, 'error' => null];

                    continue;
                }

                $error = is_array($ticket)
                    ? ($ticket['details']['error'] ?? $ticket['message'] ?? 'error')
                    : 'no ticket';

                $results[$key] = ['ok' => false, 'error' => mb_substr((string) $error, 0, 200)];

                if ($error === 'DeviceNotRegistered' && !empty($chunk[$key]['device_id'])) {
                    DB::table('mobile_devices')
                        ->where('id', $chunk[$key]['device_id'])
                        ->update(['is_active' => 0, 'updated_at' => now()]);
                }
            }
        }

        return $results;
    }
}
