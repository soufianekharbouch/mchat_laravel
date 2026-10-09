<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class CustomerAccount extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'subscription_id',

        'phone',
        'email',
        'password',

        'settings',

        'initial_password_encrypted',
        'password_changed_at',

        'access_token_hash',
        'access_token_expires_at',

        'last_login_at',

        'login_count',
        'total_app_seconds',
        'last_app_activity_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'access_token_hash',
        'initial_password_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'password' =>
                'hashed',

            'settings' =>
                'array',

            'password_changed_at' =>
                'datetime',

            'access_token_expires_at' =>
                'datetime',

            'last_login_at' =>
                'datetime',

            'last_app_activity_at' =>
                'datetime',

            'login_count' =>
                'integer',

            'total_app_seconds' =>
                'integer',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | SUBSCRIPTION
    |--------------------------------------------------------------------------
    */

    public function subscription()
    {
        return $this->belongsTo(
            Subscription::class
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ACCESS TOKEN
    |--------------------------------------------------------------------------
    */

    public function hasValidAccessToken(): bool
    {
        return !empty(
            $this->access_token_hash
        )
            &&
            !empty(
                $this->access_token_expires_at
            )
            &&
            $this
                ->access_token_expires_at
                ->isFuture();
    }

    /*
    |--------------------------------------------------------------------------
    | PASSWORD
    |--------------------------------------------------------------------------
    */

    public function usesInitialPassword(): bool
    {
        return empty(
            $this->password_changed_at
        );
    }

    public function usesOwnPassword(): bool
    {
        return !empty(
            $this->password_changed_at
        );
    }

    /*
    |--------------------------------------------------------------------------
    | CUSTOMER ADDRESSES
    |--------------------------------------------------------------------------
    */

    public function addresses(): HasMany
    {
        return $this->hasMany(
            CustomerAddress::class,
            'customer_account_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | MOBILE DEVICES
    |--------------------------------------------------------------------------
    */

    public function mobileDevices(): HasMany
    {
        return $this->hasMany(
            MobileDevice::class,
            'customer_account_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | NOTIFICATIONS
    |--------------------------------------------------------------------------
    */

    public function notificationRecipients(): HasMany
    {
        return $this->hasMany(
            MobileNotificationRecipient::class,
            'customer_account_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | APP ACTIVITIES
    |--------------------------------------------------------------------------
    */

    public function appActivities(): HasMany
    {
        return $this->hasMany(
            CustomerAppActivity::class,
            'customer_account_id'
        );
    }
    
    public function supportTickets(): HasMany
    {
        return $this->hasMany(
            SupportTicket::class,
            'customer_account_id'
        );
    }
}