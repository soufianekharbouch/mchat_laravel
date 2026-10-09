<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MobileDevice extends Model
{
    protected $table = 'mobile_devices';

    protected $fillable = [
        'device_id',
        'customer_account_id',
        'expo_push_token',
        'platform',
        'locale',
        'device_name',
        'is_active',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(
            CustomerAccount::class,
            'customer_account_id'
        );
    }

    public function notificationRecipients(): HasMany
    {
        return $this->hasMany(
            MobileNotificationRecipient::class,
            'mobile_device_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function isAndroid(): bool
    {
        return $this->platform === 'android';
    }

    public function isIos(): bool
    {
        return $this->platform === 'ios';
    }

    public function hasPushToken(): bool
    {
        return !empty(
            trim(
                (string) $this->expo_push_token
            )
        );
    }

    public function isConnectedToCustomer(): bool
    {
        return !is_null(
            $this->customer_account_id
        );
    }
}