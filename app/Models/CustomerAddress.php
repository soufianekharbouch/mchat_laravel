<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerAddress extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_account_id',
        'label',
        'first_name',
        'last_name',
        'phone',
        'address',
        'note',
        'city',
        'province',
        'postal_code',
        'country',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function customerAccount()
    {
        return $this->belongsTo(CustomerAccount::class);
    }
}