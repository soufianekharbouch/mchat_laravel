<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OtpCode extends Model
{
    protected $table = 'otp_codes';

    protected $fillable = [
        'phone',
        'purpose',
        'code_hash',
        'attempts',
        'expires_at',
        'consumed_at',
        'channel',
        'provider_message_id',
        'ip_address',
    ];

    protected $hidden = [
        'code_hash',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }
}
