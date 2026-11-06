<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * File of the media library (uploaded in the library popup of the
 * product form). Usable by every product, kept even when unused.
 */
class MediaLibraryItem extends Model
{
    protected $table = 'media_library';

    protected $fillable = [
        'type',
        'path',
        'original_name',
        'mime',
        'size',
        'uploaded_by',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function getUrlAttribute(): string
    {
        return asset('storage/app/public/' . $this->path);
    }
}
