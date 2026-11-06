<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileNotificationRecipient extends Model
{
    /** Push statuses */
    public const PUSH_STATUSES = [
        'pending' => ['label' => 'Waiting', 'color' => 'secondary'],
        'sent' => ['label' => 'Push sent', 'color' => 'success'],
        'no_device' => ['label' => 'No phone (in-app only)', 'color' => 'warning'],
        'failed' => ['label' => 'Push failed', 'color' => 'danger'],
    ];

    protected $table =
        'mobile_notification_recipients';

    protected $fillable = [
        'notification_id',
        'customer_account_id',
        'mobile_device_id',
        'push_status',
        'push_sent_at',
        'push_error',
        'viewed_at',
        'opened_at',
        'dismissed_at',
    ];

    protected function casts(): array
    {
        return [
            'push_sent_at' => 'datetime',
            'viewed_at' => 'datetime',
            'opened_at' => 'datetime',
            'dismissed_at' => 'datetime',
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
        return $this->push_status === 'pending';
    }

    public function isSent(): bool
    {
        return $this->push_status === 'sent';
    }

    public function isFailed(): bool
    {
        return $this->push_status === 'failed';
    }

    public function pushStatusConfig(): array
    {
        return self::PUSH_STATUSES[$this->push_status]
            ?? ['label' => ucfirst((string) $this->push_status), 'color' => 'secondary'];
    }

    /*
    |--------------------------------------------------------------------------
    | Tracking
    |--------------------------------------------------------------------------
    */

    /** Seen in the list of notifications of the app. */
    public function hasBeenViewed(): bool
    {
        return !is_null($this->viewed_at) || !is_null($this->opened_at);
    }

    /** Tapped (push or list): the app opened its destination. */
    public function hasBeenOpened(): bool
    {
        return !is_null($this->opened_at);
    }
}
