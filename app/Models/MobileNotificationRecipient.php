<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileNotificationRecipient extends Model
{
    protected $table =
        'mobile_notification_recipients';

    protected $fillable = [
        'notification_id',
        'customer_account_id',
        'mobile_device_id',
        'push_status',
        'push_sent_at',
        'viewed_at',
    ];

    protected function casts(): array
    {
        return [
            'push_sent_at' => 'datetime',
            'viewed_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function notification(): BelongsTo
    {
        return $this->belongsTo(
            MobileNotification::class,
            'notification_id'
        );
    }

    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(
            CustomerAccount::class,
            'customer_account_id'
        );
    }

    public function mobileDevice(): BelongsTo
    {
        return $this->belongsTo(
            MobileDevice::class,
            'mobile_device_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Push status helpers
    |--------------------------------------------------------------------------
    */

    public function isPending(): bool
    {
        return $this->push_status ===
            'pending';
    }

    public function isSent(): bool
    {
        return $this->push_status ===
            'sent';
    }

    public function isFailed(): bool
    {
        return $this->push_status ===
            'failed';
    }

    /*
    |--------------------------------------------------------------------------
    | Tracking
    |--------------------------------------------------------------------------
    */

    public function hasBeenViewed(): bool
    {
        return !is_null(
            $this->viewed_at
        );
    }
}