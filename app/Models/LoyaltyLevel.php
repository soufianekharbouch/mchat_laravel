<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Level reached by a pet with the points earned since the start
 * (points used or lost do not lower the level).
 */
class LoyaltyLevel extends Model
{
    protected $table = 'loyalty_levels';

    protected $fillable = [
        'name_en',
        'name_ar',
        'description_en',
        'description_ar',
        'min_points',
        'color',
        'icon',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'min_points' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function name(string $lang = 'en'): string
    {
        return $lang === 'ar' ? ($this->name_ar ?: $this->name_en) : ($this->name_en ?: $this->name_ar);
    }

    public function description(string $lang = 'en'): ?string
    {
        return $lang === 'ar' ? ($this->description_ar ?: $this->description_en) : ($this->description_en ?: $this->description_ar);
    }

    /** Font Awesome class, always with the "fa-" prefix. */
    public function iconClass(): string
    {
        $icon = trim((string) $this->icon) ?: 'fa-medal';

        return str_starts_with($icon, 'fa-') ? $icon : 'fa-' . $icon;
    }
}
