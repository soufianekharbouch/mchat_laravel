<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One status change of a purchase (append only).
 */
class PurchaseStatusHistory extends Model
{
    // Only created_at exists in the table
    public const UPDATED_AT = null;

    public const CHANGED_BY_TYPES = [
        'admin' => 'Admin',
        'customer' => 'Customer',
        'system' => 'System',
        'shopify' => 'Shopify',
    ];

    protected $fillable = [
        'purchase_id',
        'from_status',
        'to_status',
        'note',
        'changed_by_type',
        'changed_by_user_id',
    ];

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    /**
     * "Admin (John Doe)", "Customer", "System"...
     */
    public function getActorLabelAttribute(): string
    {
        $label = self::CHANGED_BY_TYPES[$this->changed_by_type] ?? (string) $this->changed_by_type;

        if ($this->changed_by_type === 'admin' && $this->changedBy) {
            $label .= ' (' . trim($this->changedBy->first_name . ' ' . $this->changedBy->last_name) . ')';
        }

        return $label;
    }
}
