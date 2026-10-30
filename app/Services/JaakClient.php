<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * JAAK delivery API (https://jaaknow-demo.com/api).
 *
 * Auth: Authorization: Bearer <JAAK_API_KEY>
 *
 *  GET  /me                         check the key
 *  POST /tasks                      create tasks      -> [{id}]
 *  POST /quotes                     batch + fee       -> [{batch_id, min_fee, max_fee, tasks}]
 *  POST /batches/accept             request drivers   -> {batches: [{id}]}
 *  GET  /tasks?external_id=a&...    statuses (no webhook: polled)
 */
class JaakClient
{
    public static function enabled(): bool
    {
        return (bool) config('services.jaak.enabled', false) && filled(config('services.jaak.api_key'));
    }

    public function me(): array
    {
        return $this->check($this->http()->get('/me'), 'Check API key');
    }

    /**
     * @param array<int, array> $tasks
     * @return array<int, array{id: string}>
     */
    public function createTasks(array $tasks): array
    {
        return $this->check($this->http()->post('/tasks', array_values($tasks)), 'Create task');
    }

    /**
     * @param string[] $taskIds
     * @return array<int, array> batches
     */
    public function quotes(array $taskIds): array
    {
        return $this->check(
            $this->http()->post('/quotes', ['task_ids' => array_values(array_map('strval', $taskIds))]),
            'Create batch'
        );
    }

    /**
     * @param string[] $batchIds
     */
    public function acceptBatches(array $batchIds): array
    {
        return $this->check(
            $this->http()->post('/batches/accept', ['batch_ids' => array_values(array_map('strval', $batchIds))]),
            'Request drivers'
        );
    }

    /**
     * Tasks by our external ids (external_id=a&external_id=b..., all pages).
     *
     * @param string[] $externalIds
     * @return array<int, array> tasks
     */
    public function tasksByExternalIds(array $externalIds): array
    {
        $tasks = [];

        foreach (array_chunk(array_values(array_unique($externalIds)), 40) as $chunk) {
            $after = null;

            do {
                $query = implode('&', array_map(fn ($id) => 'external_id=' . rawurlencode($id), $chunk));

                if ($after) {
                    $query .= '&after=' . rawurlencode($after);
                }

                $data = $this->check($this->http()->get('/tasks?' . $query), 'Read tasks');

                foreach ($data['data'] ?? [] as $task) {
                    $tasks[] = $task;
                }

                $after = ($data['pagination']['has_next_page'] ?? false) ? ($data['pagination']['end_cursor'] ?? null) : null;
            } while ($after);
        }

        return $tasks;
    }

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    */

    private function http(): PendingRequest
    {
        $key = (string) config('services.jaak.api_key');

        if ($key === '') {
            throw new RuntimeException('JAAK_API_KEY missing in .env.');
        }

        return Http::baseUrl(rtrim((string) config('services.jaak.base_url', 'https://jaaknow-demo.com/api'), '/'))
            ->withToken($key)
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->connectTimeout(10);
    }

    /**
     * JSON of a successful answer, otherwise an exception with the JAAK
     * error ({title, type, detail}).
     */
    private function check(Response $response, string $action): array
    {
        if ($response->successful()) {
            $data = $response->json();

            return is_array($data) ? $data : [];
        }

        $error = $response->json();
        $detail = is_array($error)
            ? trim(($error['title'] ?? '') . ' ' . ($error['detail'] ?? ''))
            : '';

        throw new RuntimeException(
            "JAAK {$action}: HTTP {$response->status()}" . ($detail !== '' ? " - {$detail}" : ' - ' . mb_substr($response->body(), 0, 300))
        );
    }
}
