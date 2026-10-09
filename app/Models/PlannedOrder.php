<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class PlannedOrder extends Model
{
    protected $fillable = [
        'subscription_id', 'animal_id', 'scheduled_for', 'status',
        'prepared_at', 'shipped_at', 'delivered_at', 'prepared_by',
        'shipped_by', 'delivered_by',
    ];

    protected $casts = [
        'scheduled_for' => 'date',
        'prepared_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function subscription() { return $this->belongsTo(Subscription::class); }
    public function animal() { return $this->belongsTo(Animal::class); }
    public function items() { return $this->hasMany(PlannedOrderItem::class); }

    public function getEffectiveStatusAttribute(): string
    {
        $status = (string) ($this->status ?? 'planned');
        $date = $this->scheduled_for
            ? Carbon::parse($this->scheduled_for)->startOfDay()
            : null;

        if (
            in_array($status, ['planned', 'prepared'], true)
            && $date
            && $date->lt(Carbon::today())
        ) {
            return 'overdue';
        }

        return in_array(
            $status,
            ['planned', 'prepared', 'shipped', 'delivered', 'overdue'],
            true
        ) ? $status : 'planned';
    }
}
