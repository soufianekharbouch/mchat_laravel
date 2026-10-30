<?php

namespace App\Services;

use App\Models\ShopifyOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * "Refresh" button of Auto subscription: Shopify orders whose webhook was
 * never received are read from the Shopify API and imported.
 *
 *  - only orders created AFTER the start of the auto subscription:
 *      SHOPIFY_SYNC_START (e.g. 2026-10-20) in .env, otherwise the date of
 *      the first order received by webhook (never the old Shopify orders)
 *  - and only the last SHOPIFY_SYNC_DAYS days (7 by default)
 *  - an order already in Auto subscription is never created twice
 *  - an order "Waiting payment" here but paid on Shopify (orders/paid
 *    webhook missed) is processed again
 *
 * Shopify app: the Admin token needs the "read_orders" access.
 */
class ShopifyOrderSync
{
    private const MAX_PAGES = 4;          // 4 x 250 orders
    private const MAX_PROCESSED = 30;     // per click (the page must answer)

    public function __construct(private ShopifyOrderImporter $importer)
    {
    }

    /**
     * @return array{checked: int, created: int, updated: int, skipped_old: int, from: Carbon, errors: string[]}
     */
    public function run(): array
    {
        $lock = Cache::lock('shopify-order-sync', 300);

        if (!$lock->get()) {
            throw new RuntimeException('A refresh is already running: try again in a minute.');
        }

        try {
            return $this->sync();
        } finally {
            $lock->release();
        }
    }

    /** First date read on Shopify (never before the start of the system). */
    public function fromDate(): Carbon
    {
        $start = $this->systemStart();
        $days = max(1, (int) config('services.shopify.sync_days', 7));
        $from = now()->subDays($days)->startOfDay();

        return $start && $start->gt($from) ? $start : $from;
    }

    /** Start of the auto subscription (.env, otherwise first webhook order). */
    public function systemStart(): ?Carbon
    {
        $configured = trim((string) config('services.shopify.sync_start', ''));

        if ($configured !== '') {
            try {
                return Carbon::parse($configured, config('app.timezone'))->startOfDay();
            } catch (Throwable) {
                // wrong date in .env: first order used
            }
        }

        $first = ShopifyOrder::query()
            ->where('topic', '!=', 'sync/refresh')
            ->min('created_at');

        return $first ? Carbon::parse($first)->startOfDay() : null;
    }

    private function sync(): array
    {
        $from = $this->fromDate();
        $start = $this->systemStart();

        $result = ['checked' => 0, 'created' => 0, 'updated' => 0, 'skipped_old' => 0, 'from' => $from, 'errors' => []];

        if (!$start) {
            throw new RuntimeException('Start date of the auto subscription unknown: add SHOPIFY_SYNC_START=YYYY-MM-DD in .env.');
        }

        $processed = 0;

        foreach ($this->fetchOrders($from) as $payload) {
            $result['checked']++;

            $id = (string) ($payload['id'] ?? '');

            if ($id === '') {
                continue;
            }

            // Safety: never an order before the start of the system
            $createdAt = isset($payload['created_at']) ? Carbon::parse($payload['created_at']) : null;

            if (!$createdAt || $createdAt->lt($start)) {
                $result['skipped_old']++;
                continue;
            }

            $order = ShopifyOrder::where('shopify_order_id', $id)->first();

            // Already here and processed / ignored / failed: not touched
            if ($order && $order->status !== 'pending') {
                continue;
            }

            // Here "Waiting payment" and still not paid on Shopify: nothing new
            if ($order && ($payload['financial_status'] ?? null) === $order->financial_status && empty($payload['cancelled_at'])) {
                continue;
            }

            if ($processed >= self::MAX_PROCESSED) {
                $result['errors'][] = 'More orders to import: click "Refresh" again.';
                break;
            }

            try {
                $isNew = !$order;
                $order = $order ?: new ShopifyOrder(['shopify_order_id' => $id]);

                $order->payload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $order->topic = $isNew ? 'sync/refresh' : $order->topic;
                $this->importer->fillSummary($order, $payload);

                if ($isNew) {
                    $order->status = 'pending';
                }

                $order->save();

                $this->importer->process($order);
                $processed++;

                $isNew ? $result['created']++ : $result['updated']++;
            } catch (Throwable $exception) {
                report($exception);
                $result['errors'][] = ($payload['name'] ?? $id) . ': ' . mb_substr($exception->getMessage(), 0, 150);
            }
        }

        Log::info('Shopify orders refresh', collect($result)->except('from')->all() + ['from' => $from->toDateTimeString()]);

        return $result;
    }

    /**
     * Orders created since $from (any status), oldest first.
     * Same JSON as the webhooks (REST Admin API).
     */
    private function fetchOrders(Carbon $from): array
    {
        $domain = (string) config('services.shopify.store_domain');
        $token = (string) config('services.shopify.admin_access_token');
        $version = (string) config('services.shopify.api_version', '2026-07');

        if ($domain === '' || $token === '') {
            throw new RuntimeException('Shopify is not configured (SHOPIFY_STORE_DOMAIN / SHOPIFY_ADMIN_ACCESS_TOKEN).');
        }

        $url = "https://{$domain}/admin/api/{$version}/orders.json";
        $query = [
            'status' => 'any',
            'created_at_min' => $from->copy()->toIso8601String(),
            'limit' => 250,
            'order' => 'created_at asc',
        ];

        $orders = [];

        for ($page = 0; $page < self::MAX_PAGES && $url; $page++) {
            $response = Http::withHeaders(['X-Shopify-Access-Token' => $token])
                ->acceptJson()
                ->timeout(25)
                ->get($url, $query);

            if ($response->status() === 403 || $response->status() === 401) {
                throw new RuntimeException('Shopify refused to read the orders (HTTP ' . $response->status()
                    . '): give the "read_orders" access to the Shopify app (Settings > Apps > Develop apps > Admin API scopes), then reinstall it.');
            }

            if (!$response->successful()) {
                Log::warning('Shopify orders refresh error', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]);

                throw new RuntimeException('Shopify API error (HTTP ' . $response->status() . ').');
            }

            foreach ($response->json('orders') ?? [] as $order) {
                if (is_array($order)) {
                    $orders[] = $order;
                }
            }

            // Next page: Link: <...page_info=xxx>; rel="next" (no other filter allowed with page_info)
            $url = null;
            $query = [];

            if (preg_match('/<([^>]+)>;\s*rel="next"/', (string) $response->header('Link'), $match)) {
                $url = $match[1];
            }
        }

        return $orders;
    }
}
