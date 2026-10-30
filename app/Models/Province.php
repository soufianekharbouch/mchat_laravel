<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Province extends Model
{
    protected $fillable = [
        'name',     // anglais
        'name_ar',  // arabe
        'code',
    ];

    public function zones(): HasMany
    {
        return $this->hasMany(Zone::class);
    }

    /**
     * Libellé selon la langue (ex: $province->label('ar')).
     */
    public function label(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        return $locale === 'ar' && filled($this->name_ar)
            ? $this->name_ar
            : $this->name;
    }
}
