<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One image or video of a product (gallery of the app).
 * The product "image" column stays its main image (cover).
 */
class ProductMedia extends Model
{
    protected $table = 'product_media';

    public const TYPE_IMAGE = 'image';
    public const TYPE_VIDEO = 'video';

    /** Limits of the product form */
    public const MAX_PER_PRODUCT = 10;
    public const MAX_IMAGE_KB = 4096;     // 4 MB
    public const MAX_VIDEO_KB = 51200;    // 50 MB

    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];
    public const VIDEO_EXTENSIONS = ['mp4', 'mov', 'webm', 'm4v'];

    protected $fillable = [
        'product_id',
        'type',
        'path',
        'mime',
        'size',
        'sort_order',
    ];

    protected $casts = [
        'size' => 'integer',
        'sort_order' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isVideo(): bool
    {
        return $this->type === self::TYPE_VIDEO;
    }

    /** Same public path as the other images of the app. */
    public function getUrlAttribute(): string
    {
        return asset('storage/app/public/' . $this->path);
    }

    public function sizeLabel(): string
    {
        $bytes = (int) $this->size;

        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1) . ' MB'
            : number_format(max(1, $bytes) / 1024, 0) . ' KB';
    }
}
