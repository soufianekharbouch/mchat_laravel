<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerServiceReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'subscription_id',
        'user_id',
        'contact_date',
        'contact_time',
        'subject',
        'summary',
        'channel',
    ];

    protected $casts = [
        'contact_date' => 'date',
    ];

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
