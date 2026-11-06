<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * One online payment attempt (Hesabe) for one or several packs.
 */
class PaymentTransaction extends Model
{
    public const STATUS_INITIATED = 'initiated';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'order_reference',
        'gateway',
        'customer_account_id',
        'purchase_ids',
        'amount',
        'currency',
        'payment_method',
        'status',
        'checkout_token',
        'payment_token',
        'payment_id',
        'result_code',
        'gateway_response',
        'failure_reason',
        'paid_at',
        'checked_at',
    ];

    protected $casts = [
        'purchase_ids' => 'array',
        'gateway_response' => 'array',
        'amount' => 'decimal:3',
        'paid_at' => 'datetime',
        'checked_at' => 'datetime',
    ];

    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }

    /** Packs paid by this payment. */
    public function purchases(): Collection
    {
        $ids = array_map('intval', $this->purchase_ids ?? []);

        return $ids ? Purchase::whereIn('id', $ids)->orderBy('id')->get() : collect();
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_INITIATED;
    }
}
