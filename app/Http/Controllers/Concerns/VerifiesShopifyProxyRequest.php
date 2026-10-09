<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

trait VerifiesShopifyProxyRequest
{
    protected function isValidShopifyProxyRequest(
        Request $request
    ): bool {
        if (
            !$request->has('shop') ||
            !$request->has('signature') ||
            !$request->has('path_prefix')
        ) {
            return false;
        }

        $signature = (string) $request->query('signature');

        $params = $request->query();

        unset($params['signature']);

        ksort($params);

        $message = collect($params)
            ->map(function ($value, $key) {
                if (is_array($value)) {
                    return collect($value)
                        ->map(function ($item) use ($key) {
                            return $key . '=' . $item;
                        })
                        ->implode('');
                }

                return $key . '=' . $value;
            })
            ->implode('');

        $secret = config('services.shopify.api_secret');

        if (!$secret) {
            return false;
        }

        $calculatedSignature = hash_hmac(
            'sha256',
            $message,
            $secret
        );

        return hash_equals(
            $calculatedSignature,
            $signature
        );
    }

    protected function shopifyDashboardUrl(
        array $query = []
    ): string {
        $baseUrl = rtrim(
            config(
                'services.shopify.storefront_url',
                'https://maisonchatkw.com'
            ),
            '/'
        );

        $proxyPath = '/' . ltrim(
            config(
                'services.shopify.proxy_path',
                '/apps/customer-dashboard'
            ),
            '/'
        );

        $url = $baseUrl . $proxyPath;

        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }
}