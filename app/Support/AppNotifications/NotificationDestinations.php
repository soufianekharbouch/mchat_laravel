<?php

namespace App\Support\AppNotifications;

use App\Models\Product;

/**
 * Where the app goes when the customer taps a notification.
 *
 * The key is sent to the app (push data + list of notifications) with
 * its params. Add a screen here, then in the app (same key).
 *
 * manual : can be chosen when the team creates a notification
 * params : values needed by the screen (product_id, animal_id...)
 */
class NotificationDestinations
{
    public const DEFAULT = 'notifications';

    public const DESTINATIONS = [
        'notifications' => ['label' => 'Notifications list', 'params' => [], 'manual' => true],
        'home' => ['label' => 'Home', 'params' => [], 'manual' => true],
        'shop' => ['label' => 'Shop', 'params' => [], 'manual' => true],
        'product' => ['label' => 'A product of the shop', 'params' => ['product_id'], 'manual' => true],
        'pets' => ['label' => 'My pets', 'params' => [], 'manual' => true],
        'deliveries' => ['label' => 'My deliveries', 'params' => [], 'manual' => true],
        'loyalty' => ['label' => 'Loyalty points', 'params' => [], 'manual' => true],
        'support' => ['label' => 'Help & support', 'params' => [], 'manual' => true],

        // Automatic only (they need the pet / pack / order of the customer)
        'pet' => ['label' => 'Pet page', 'params' => ['animal_id'], 'manual' => false],
        'pet_plans' => ['label' => 'Plans of the pet (renew / subscribe)', 'params' => ['animal_id'], 'manual' => false],
        'plan_deliveries' => ['label' => 'Plan the deliveries of the pack', 'params' => ['purchase_id'], 'manual' => false],
        'delivery' => ['label' => 'Delivery tracking', 'params' => ['planned_order_id'], 'manual' => false],
    ];

    public static function exists(?string $key): bool
    {
        return $key !== null && isset(self::DESTINATIONS[$key]);
    }

    public static function label(?string $key): string
    {
        return self::DESTINATIONS[$key]['label'] ?? ($key ?: self::DESTINATIONS[self::DEFAULT]['label']);
    }

    /** Destinations the team can choose for a manual notification. */
    public static function manual(): array
    {
        return array_filter(self::DESTINATIONS, fn ($destination) => $destination['manual']);
    }

    /**
     * Destinations possible for an automatic type: the ones whose params
     * the type gives.
     */
    public static function forType(string $typeKey): array
    {
        $available = NotificationTypes::get($typeKey)['params'] ?? [];

        return array_filter(
            self::DESTINATIONS,
            fn ($destination) => array_diff($destination['params'], $available) === []
        );
    }

    /** "A product of the shop: Chicken pack" */
    public static function describe(?string $key, ?array $params): string
    {
        $label = self::label($key ?: self::DEFAULT);
        $params = $params ?? [];

        if ($key === 'product' && !empty($params['product_id'])) {
            $product = Product::find($params['product_id']);

            return $label . ': ' . ($product ? "{$product->code} - {$product->name}" : '#' . $params['product_id']);
        }

        $needed = self::DESTINATIONS[$key]['params'] ?? [];
        $shown = collect($needed)
            ->filter(fn ($name) => !empty($params[$name]))
            ->map(fn ($name) => str_replace('_id', '', $name) . ' #' . $params[$name])
            ->implode(', ');

        return $shown !== '' ? "{$label} ({$shown})" : $label;
    }
}
