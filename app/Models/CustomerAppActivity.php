<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAppActivity extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'customer_account_id',
        'mobile_device_id',
        'code',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];


    /*
    |--------------------------------------------------------------------------
    | ACTIVITY CODES
    |--------------------------------------------------------------------------
    */

    public const LOGIN =
        'LOGIN';

    public const PASSWORD_CHANGED =
        'PASSWORD_CHANGED';

    public const PHONE_CHANGED =
        'PHONE_CHANGED';

    public const PROFILE_UPDATED =
        'PROFILE_UPDATED';

    public const ADDRESS_CHANGED =
        'ADDRESS_CHANGED';

    public const DELIVERY_DATE_CHANGED =
        'DELIVERY_DATE_CHANGED';

    public const DELIVERY_SLOT_CHANGED =
        'DELIVERY_SLOT_CHANGED';

    public const PET_UPDATED =
        'PET_UPDATED';


    /*
    |--------------------------------------------------------------------------
    | RELATIONS
    |--------------------------------------------------------------------------
    */

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
    | RECORD
    |--------------------------------------------------------------------------
    */

    public static function record(
        int $customerAccountId,
        string $code,
        ?array $metadata = null,
        ?int $mobileDeviceId = null
    ): self {
        return self::query()
            ->create([
                'customer_account_id' =>
                    $customerAccountId,

                'mobile_device_id' =>
                    $mobileDeviceId,

                'code' =>
                    $code,

                'metadata' =>
                    $metadata,

                'created_at' =>
                    now(),
            ]);
    }


    /*
    |--------------------------------------------------------------------------
    | HUMAN LABEL
    |--------------------------------------------------------------------------
    */

    public function getLabelAttribute(): string
    {
        return match ($this->code) {

            self::LOGIN =>
                'signed in to the mobile app',

            self::PASSWORD_CHANGED =>
                'changed their password',

            self::PHONE_CHANGED =>
                'changed their phone number',

            self::PROFILE_UPDATED =>
                'updated their profile information',

            self::ADDRESS_CHANGED =>
                'changed their address',

            self::DELIVERY_DATE_CHANGED =>
                'changed a delivery date',

            self::DELIVERY_SLOT_CHANGED =>
                'changed their delivery slot',

            self::PET_UPDATED =>
                'updated pet information',

            default =>
                'performed an app action',
        };
    }
}