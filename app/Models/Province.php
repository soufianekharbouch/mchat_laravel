<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Province extends Model
{
    protected $fillable = ['name', 'code'];

    public function zones()
    {
        return $this->hasMany(Zone::class);
    }
}