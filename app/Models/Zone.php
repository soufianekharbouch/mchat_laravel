<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Zone extends Model
{
    protected $fillable = [
        'province_id',
        'name',     // anglais
        'name_ar',  // arabe
    ];

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function label(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        return $locale === 'ar' && filled($this->name_ar)
            ? $this->name_ar
            : $this->name;
    }
}
