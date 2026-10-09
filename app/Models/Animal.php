<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Animal extends Model
{
    use HasFactory;

    protected $fillable = [
        'subscription_id', 'name', 'species', 'breed_size_category', 'age',
        'breed', 'weight_kg', 'sex', 'allergies', 'preferences',
        'health_status', 'note', 'photo_path', 'transition', 'qr_code_image',
        'last_order', 'last_order_qr_code_image', 'subscription_start',
        'subscription_end',
    ];

    protected $casts = [
        'subscription_start' => 'date',
        'subscription_end' => 'date',
        'transition' => 'boolean',
        'last_order' => 'boolean',
        'weight_kg' => 'decimal:2',
    ];

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function mealDates()
    {
        return $this->hasMany(AnimalMealDate::class);
    }

    public function plannedOrders()
    {
        return $this->hasMany(PlannedOrder::class);
    }

    public function hasActiveSubscription(?Carbon $date = null): bool
    {
        $date = ($date ?: Carbon::today())->copy()->startOfDay();
        $start = $this->subscription_start?->copy()->startOfDay();
        $end = $this->subscription_end?->copy()->endOfDay();

        if ($start && $end) return $date->between($start, $end);
        if ($start) return $start->lte($date);
        if ($end) return $date->lte($end);
        return true;
    }

    public function getSubscriptionStatusAttribute(): string
    {
        return $this->hasActiveSubscription() ? 'active' : 'inactive';
    }
}
