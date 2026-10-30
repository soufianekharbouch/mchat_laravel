<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the points history of a pet.
 * is_auto = true : calculated from the purchases (rebuilt automatically)
 * is_auto = false: manual adjustment from the back-office
 */
class PetLoyaltyTransaction extends Model
{
    protected $table = 'pet_loyalty_transactions';

    public const TYPES = [
        'trial' => ['label' => 'Test pack', 'label_ar' => 'باقة التذوق', 'color' => 'info', 'icon' => 'fa-gift'],
        'regular' => ['label' => 'Subscription', 'label_ar' => 'اشتراك', 'color' => 'primary', 'icon' => 'fa-box'],
        'premium' => ['label' => 'Premium subscription', 'label_ar' => 'اشتراك بريميوم', 'color' => 'warning', 'icon' => 'fa-crown'],
        'redeem' => ['label' => 'Points used', 'label_ar' => 'نقاط مستخدمة', 'color' => 'mauve', 'icon' => 'fa-tag'],
        'trial_lost' => ['label' => 'Test pack points lost', 'label_ar' => 'انتهت نقاط باقة التذوق', 'color' => 'secondary', 'icon' => 'fa-hourglass-end'],
        'expired' => ['label' => 'All points lost (no new subscription)', 'label_ar' => 'انتهت صلاحية النقاط', 'color' => 'danger', 'icon' => 'fa-ban'],
        'adjust' => ['label' => 'Manual adjustment', 'label_ar' => 'تعديل يدوي', 'color' => 'dark', 'icon' => 'fa-user-pen'],
    ];

    protected $fillable = [
        'animal_id',
        'subscription_id',
        'purchase_id',
        'type',
        'points',
        'balance_after',
        'number',
        'is_auto',
        'note',
        'happened_at',
        'created_by',
    ];

    protected $casts = [
        'points' => 'integer',
        'balance_after' => 'integer',
        'number' => 'integer',
        'is_auto' => 'boolean',
        'happened_at' => 'datetime',
    ];

    public function animal(): BelongsTo
    {
        return $this->belongsTo(Animal::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function typeLabel(string $lang = 'en'): string
    {
        $config = self::TYPES[$this->type] ?? null;

        if (!$config) {
            return ucfirst((string) $this->type);
        }

        $label = $lang === 'ar' ? $config['label_ar'] : $config['label'];

        return $this->number && in_array($this->type, ['regular', 'premium'], true)
            ? "{$label} #{$this->number}"
            : $label;
    }

    public function typeColor(): string
    {
        return self::TYPES[$this->type]['color'] ?? 'secondary';
    }

    public function typeIcon(): string
    {
        return self::TYPES[$this->type]['icon'] ?? 'fa-circle';
    }
}
