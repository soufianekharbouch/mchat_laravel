<?php

namespace App\Http\Controllers;

use App\Models\ShopifyOrder;
use App\Services\ShopifyOrderImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Shopify webhooks (orders).
 *
 * POST /api/shopify/webhooks/orders
 *
 * Subscribe in Shopify (Settings > Notifications > Webhooks), format JSON:
 *   - Order creation  (orders/create)
 *   - Order payment   (orders/paid)
 *   - Order update    (orders/updated)   optional
 *
 * The signature (X-Shopify-Hmac-Sha256) is checked with
 * SHOPIFY_WEBHOOK_SECRET (key shown under the webhook list in Shopify).
 *
 * Shopify needs an answer within 5 seconds: the order is saved,
 * the answer is sent, then the order is processed.
 */
class ShopifyWebhookController extends Controller
{
    public function orders(Request $request, ShopifyOrderImporter $importer): JsonResponse
    {
        $raw = $request->getContent();

        if (!$this->validSignature($raw, (string) $request->header('X-Shopify-Hmac-Sha256'))) {
            Log::warning('Shopify webhook: invalid signature', ['ip' => $request->ip()]);

            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $payload = json_decode($raw, true);
        $topic = (string) $request->header('X-Shopify-Topic', 'orders/create');

        Log::info('Shopify webhook received', ['topic' => $topic, 'id' => $payload['id'] ?? null]);

        // Only orders (a "Product creation" webhook sent here is ignored)
        if (!str_starts_with($topic, 'orders/')) {
            return response()->json(['message' => "Topic {$topic} ignored"], 200);
        }

        if (!is_array($payload) || empty($payload['id'])) {
            return response()->json(['message' => 'Ignored'], 200);
        }

        $order = ShopifyOrder::firstOrNew(['shopify_order_id' => (string) $payload['id']]);

        // Last version of the order (paid, updated...) kept until it is processed
        $alreadyDone = $order->exists && in_array($order->status, ['processed', 'needs_review', 'ignored'], true);

        if (!$alreadyDone) {
            $order->payload = $raw;
            $order->topic = $topic;
            $importer->fillSummary($order, $payload);
        }

        if (!$order->exists) {
            $order->status = 'pending';
        }

        $order->save();

        if (!$alreadyDone) {
            $orderId = $order->id;

            // After the answer to Shopify
            dispatch(function () use ($orderId) {
                $shopifyOrder = ShopifyOrder::find($orderId);

                if ($shopifyOrder) {
                    app(ShopifyOrderImporter::class)->process($shopifyOrder);
                }
            })->afterResponse();
        }

        return response()->json(['message' => 'OK'], 200);
    }

    private function validSignature(string $raw, string $header): bool
    {
        $secret = (string) (config('services.shopify.webhook_secret') ?: config('services.shopify.api_secret'));

        if ($secret === '' || $header === '') {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $raw, $secret, true));

        return hash_equals($expected, $header);
    }
}
