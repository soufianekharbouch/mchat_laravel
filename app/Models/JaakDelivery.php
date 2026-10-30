<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One JAAK delivery = one customer, one day, one slot, one address.
 * It can carry the orders of several pets (jaak_delivery_orders).
 */
class JaakDelivery extends Model
{
    public const STATUSES = [
        'queued' => ['label' => 'Not sent', 'color' => 'secondary', 'icon' => 'fa-clock'],
        'waiting' => ['label' => 'Waiting other pets', 'color' => 'secondary', 'icon' => 'fa-hourglass-half'],
        'PAUSED' => ['label' => 'Waiting driver request', 'color' => 'info', 'icon' => 'fa-pause-circle'],
        'PENDING' => ['label' => 'Assigning driver', 'color' => 'primary', 'icon' => 'fa-user-clock'],
        'PICKUP' => ['label' => 'Driver to kitchen', 'color' => 'warning', 'icon' => 'fa-motorcycle'],
        'DROPOFF' => ['label' => 'On the way', 'color' => 'warning', 'icon' => 'fa-shipping-fast'],
        'DELIVERED' => ['label' => 'Delivered', 'color' => 'success', 'icon' => 'fa-check-circle'],
        'CANCELLED' => ['label' => 'Cancelled', 'color' => 'dark', 'icon' => 'fa-ban'],
        'failed' => ['label' => 'Send failed', 'color' => 'danger', 'icon' => 'fa-exclamation-triangle'],
    ];

    /** JAAK statuses still moving (polled every 10 minutes). */
    public const OPEN_STATUSES = ['PAUSED', 'PENDING', 'PICKUP', 'DROPOFF'];

    public const TERMINAL_STATUSES = ['DELIVERED', 'CANCELLED'];

    protected $fillable = [
        'planned_order_id',
        'group_key',
        'subscription_id',
        'delivery_date',
        'delivery_slot',
        'external_id',
        'jaak_task_id',
        'jaak_batch_id',
        'status',
        'min_fee',
        'max_fee',
        'currency',
        'recipient_name',
        'recipient_phone',
        'address_text',
        'zone_name',
        'attempts',
        'last_error',
        'request_payload',
        'history',
        'sent_at',
        'drivers_requested_at',
        'picked_up_at',
        'delivered_at',
        'last_synced_at',
    ];

    protected $casts = [
        'history' => 'array',
        'delivery_date' => 'date',
        'min_fee' => 'decimal:3',
        'max_fee' => 'decimal:3',
        'sent_at' => 'datetime',
        'drivers_requested_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'delivered_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    /** First order of the delivery (main one). */
    public function plannedOrder(): BelongsTo
    {
        return $this->belongsTo(PlannedOrder::class);
    }

    /** All the orders (pets) carried by this delivery. */
    public function plannedOrders(): BelongsToMany
    {
        return $this->belongsToMany(PlannedOrder::class, 'jaak_delivery_orders')->withTimestamps();
    }

    /** Delivery carrying this order (null if none). */
    public static function forOrder(int $plannedOrderId): ?self
    {
        return self::whereHas('plannedOrders', fn ($query) => $query->where('planned_orders.id', $plannedOrderId))
            ->latest('id')
            ->first();
    }

    public function statusConfig(): array
    {
        return self::STATUSES[$this->status] ?? ['label' => $this->status, 'color' => 'secondary', 'icon' => 'fa-circle'];
    }

    public function isOnJaak(): bool
    {
        return filled($this->jaak_task_id);
    }

    public function canBeSent(): bool
    {
        return !$this->isOnJaak() || $this->status === 'CANCELLED';
    }

    /** Adds a line to the history (shown on the order page). */
    public function log(string $message, array $context = []): void
    {
        $history = $this->history ?? [];

        $history[] = array_filter([
            'at' => now()->format('Y-m-d H:i:s'),
            'message' => $message,
            'context' => $context ?: null,
        ]);

        $this->history = array_slice($history, -50);
    }

    /** "MC-D-45": our delivery id given to JAAK (external_id). */
    public static function externalIdFor(int $deliveryId): string
    {
        $prefix = config('services.jaak.external_prefix', 'MC');

        return "{$prefix}-D-{$deliveryId}";
    }
}
