<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Products + variants of the Shopify store (Admin GraphQL API).
 *
 * Uses config/services.php -> shopify.store_domain / admin_access_token / api_version.
 * Result cached 10 minutes (refresh() to reload).
 *
 * [
 *   ['id' => '8123', 'title' => 'Fresh Pack', 'status' => 'ACTIVE', 'image' => '...',
 *    'variants' => [['id' => '4501', 'title' => '1 month', 'sku' => 'FP-1M', 'price' => '25.000'], ...]],
 * ]
 */
class ShopifyCatalogService
{
    private const CACHE_KEY = 'shopify_catalog_products_v1';

    private const CACHE_MINUTES = 10;

    private const MAX_PAGES = 20; // 20 x 100 products

    public function products(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            now()->addMinutes(self::CACHE_MINUTES),
            fn () => $this->fetchAll()
        );
    }

    public function refresh(): array
    {
        Cache::forget(self::CACHE_KEY);

        return $this->products();
    }

    /**
     * Variant id => "Product — Variant" (labels for lists / show pages).
     */
    public function variantLabel(?string $variantId): ?string
    {
        if (!$variantId) {
            return null;
        }

        try {
            foreach ($this->products() as $product) {
                foreach ($product['variants'] as $variant) {
                    if ((string) $variant['id'] === (string) $variantId) {
                        return $product['title'] . ' — ' . $variant['title'];
                    }
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | API
    |--------------------------------------------------------------------------
    */

    private function fetchAll(): array
    {
        $domain = (string) config('services.shopify.store_domain');
        $token = (string) config('services.shopify.admin_access_token');
        $version = (string) config('services.shopify.api_version', '2026-07');

        if ($domain === '' || $token === '') {
            throw new RuntimeException('Shopify is not configured (SHOPIFY_STORE_DOMAIN / SHOPIFY_ADMIN_ACCESS_TOKEN).');
        }

        $query = <<<'GQL'
query Catalog($cursor: String) {
  products(first: 100, after: $cursor, sortKey: TITLE) {
    pageInfo { hasNextPage endCursor }
    nodes {
      legacyResourceId
      title
      status
      featuredImage { url }
      variants(first: 100) {
        nodes { legacyResourceId title sku price }
      }
    }
  }
}
GQL;

        $products = [];
        $cursor = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = Http::withHeaders([
                    'X-Shopify-Access-Token' => $token,
                ])
                ->acceptJson()
                ->asJson()
                ->timeout(25)
                ->post("https://{$domain}/admin/api/{$version}/graphql.json", [
                    'query' => $query,
                    'variables' => ['cursor' => $cursor],
                ]);

            if (!$response->successful()) {
                Log::warning('Shopify catalog error', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]);
                throw new RuntimeException('Shopify API error (HTTP ' . $response->status() . ').');
            }

            if ($errors = $response->json('errors')) {
                Log::warning('Shopify catalog GraphQL error', ['errors' => $errors]);
                throw new RuntimeException('Shopify API error: ' . ($errors[0]['message'] ?? 'unknown'));
            }

            $data = $response->json('data.products');

            foreach ($data['nodes'] ?? [] as $node) {
                $products[] = [
                    'id' => (string) $node['legacyResourceId'],
                    'title' => (string) $node['title'],
                    'status' => (string) ($node['status'] ?? ''),
                    'image' => $node['featuredImage']['url'] ?? null,
                    'variants' => collect($node['variants']['nodes'] ?? [])
                        ->map(fn ($variant) => [
                            'id' => (string) $variant['legacyResourceId'],
                            'title' => (string) $variant['title'],
                            'sku' => $variant['sku'] ?: null,
                            'price' => $variant['price'] ?? null,
                        ])
                        ->values()
                        ->all(),
                ];
            }

            if (!($data['pageInfo']['hasNextPage'] ?? false)) {
                break;
            }

            $cursor = $data['pageInfo']['endCursor'];
        }

        return $products;
    }
}
