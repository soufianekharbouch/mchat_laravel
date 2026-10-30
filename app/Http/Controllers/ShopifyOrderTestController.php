<?php

namespace App\Http\Controllers;

use App\Models\Province;
use App\Models\ShopifyOrder;
use App\Models\Zone;
use App\Services\ShopifyOrderImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use Throwable;

/**
 * TEMPORARY test page of the Shopify import.
 *
 * GET  /auto-subscriptions-test          form
 * POST /auto-subscriptions-test          simulate (nothing saved) or import
 *
 * The order is read from Shopify (Admin API, order id or "#1001"),
 * or pasted as JSON (same data as the webhook).
 *
 * "Simulate": the whole import runs inside a database transaction that is
 * cancelled at the end -> you see exactly what would be created, nothing stays.
 *
 * Remove the routes when the tests are finished.
 */
class ShopifyOrderTestController extends Controller
{
    public function form(): View
    {
        $this->authorizePermission('auto_subscriptions.test');

        return view('auto-subscriptions.test', ['result' => null]);
    }

    public function run(Request $request, ShopifyOrderImporter $importer): View|RedirectResponse
    {
        $this->authorizePermission('auto_subscriptions.test');

        $request->validate([
            'order' => ['nullable', 'string', 'max:100'],
            'json' => ['nullable', 'string'],
            'mode' => ['required', 'in:simulate,import'],
        ]);

        /*
         * 1. The order data
         */
        try {
            $payload = filled($request->input('json'))
                ? $this->fromJson((string) $request->input('json'))
                : $this->fromShopify((string) $request->input('order'));
        } catch (Throwable $exception) {
            return back()->withInput()->withErrors(['order' => $exception->getMessage()]);
        }

        // Test orders are often cancelled / not paid: treated as paid if asked
        if ($request->boolean('force_paid')) {
            $payload['financial_status'] = 'paid';
            $payload['cancelled_at'] = null;
        }

        $mode = $request->input('mode');

        /*
         * 2. Import (or simulation, cancelled at the end)
         */
        DB::beginTransaction();

        try {
            $order = ShopifyOrder::firstOrNew(['shopify_order_id' => (string) $payload['id']]);
            $order->payload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $order->topic = 'manual/test';
            $importer->fillSummary($order, $payload);

            if (!$order->exists) {
                $order->status = 'pending';
            }

            $order->save();

            $order = $importer->process($order, true)->fresh();

            $result = [
                'mode' => $mode,
                'order_id' => $order->id,
                'name' => $order->shopify_order_name,
                'status' => $order->status,
                'status_config' => $order->statusConfig(),
                'financial_status' => $order->financial_status,
                'phone_raw' => $order->phone_raw,
                'phone' => $order->phone,
                'customer' => $order->customerName(),
                'customer_created' => $order->customer_created,
                'province_raw' => $order->province_raw,
                'province' => $order->province_id ? Province::find($order->province_id)?->name : null,
                'zone' => $order->zone_id ? Zone::find($order->zone_id)?->name : null,
                'address' => $order->address_raw,
                'line_items' => $order->line_items ?? [],
                'issues' => $order->issues ?? [],
                'purchases' => $order->purchases()
                    ->with(['deliveries' => fn ($q) => $q->where('status', '!=', 'cancelled')->orderBy('delivery_date'), 'deliveries.items'])
                    ->get()
                    ->map(fn ($purchase) => [
                        'reference' => $purchase->reference,
                        'product' => $purchase->product_name,
                        'animal' => $purchase->animal_name,
                        'status' => $purchase->status,
                        'total' => number_format((float) $purchase->total_amount, 3) . ' ' . $purchase->currency,
                        'deliveries' => $purchase->deliveries->map(fn ($d) => [
                            'date' => $d->delivery_date?->format('D d/m/Y'),
                            'items' => $d->items->map(fn ($i) => $i->quantity . '× ' . $i->recipe_name)->implode(', '),
                        ])->all(),
                    ])
                    ->all(),
            ];

            if ($mode === 'simulate') {
                DB::rollBack(); // nothing is kept
            } else {
                DB::commit();
            }
        } catch (Throwable $exception) {
            DB::rollBack();
            report($exception);

            return back()->withInput()->withErrors(['order' => 'Error: ' . $exception->getMessage()]);
        }

        if ($mode === 'import') {
            return redirect()
                ->route('auto-subscriptions.show', $result['order_id'])
                ->with('success', 'Order imported (test page).');
        }

        return view('auto-subscriptions.test', ['result' => $result]);
    }

    /*
    |--------------------------------------------------------------------------
    | ORDER DATA
    |--------------------------------------------------------------------------
    */

    private function fromJson(string $json): array
    {
        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new \RuntimeException('Invalid JSON.');
        }

        // {"order": {...}} (Admin API) or {...} (webhook)
        $order = isset($data['order']) && is_array($data['order']) ? $data['order'] : $data;

        if (empty($order['id'])) {
            throw new \RuntimeException('The JSON has no order "id".');
        }

        return $order;
    }

    /**
     * Order from the Shopify Admin API: id (820982911946154508) or name (#1001).
     * The app token needs the "read_orders" access scope.
     */
    private function fromShopify(string $value): array
    {
        $value = trim($value);

        if ($value === '') {
            throw new \RuntimeException('Enter a Shopify order id / number, or paste the JSON.');
        }

        $domain = config('services.shopify.store_domain');
        $token = config('services.shopify.admin_access_token');
        $version = config('services.shopify.api_version', '2026-07');

        if (!$domain || !$token) {
            throw new \RuntimeException('SHOPIFY_STORE_DOMAIN / SHOPIFY_ADMIN_ACCESS_TOKEN missing in .env.');
        }

        $base = "https://{$domain}/admin/api/{$version}";
        $http = Http::withHeaders(['X-Shopify-Access-Token' => $token])->acceptJson()->timeout(20);

        // Long number = order id, otherwise order number / name (#1001, 1001)
        $digits = preg_replace('/\D+/', '', $value);

        if (strlen($digits) >= 10 && !str_starts_with($value, '#')) {
            $response = $http->get("{$base}/orders/{$digits}.json", ['status' => 'any']);
            $order = $response->json('order');
        } else {
            $response = $http->get("{$base}/orders.json", ['name' => $digits, 'status' => 'any', 'limit' => 1]);
            $order = $response->json('orders.0');
        }

        if ($response->status() === 403) {
            throw new \RuntimeException('Shopify refused (403): give the "read_orders" access scope to the app token.');
        }

        if ($response->failed() && $response->status() !== 404) {
            throw new \RuntimeException('Shopify error ' . $response->status() . ': ' . mb_substr($response->body(), 0, 200));
        }

        if (!is_array($order) || empty($order['id'])) {
            throw new \RuntimeException("Order \"{$value}\" not found on Shopify.");
        }

        return $order;
    }

    private function authorizePermission(string $permission): void
    {
        $user = auth()->user();

        abort_unless($user && $user->hasPermission($permission), 403);
    }
}
