<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GalleryImage extends Model
{
    protected $table = 'temple_gallery_images';

    protected $fillable = [
        'title',
        'description',
        'image_path',
        'thumbnail_path',
        'medium_path',
        'category',
        'is_wallpaper',
        'sort_order',
        'uploaded_by',
    ];

    protected $casts = [
        'is_wallpaper' => 'boolean',
        'sort_order' => 'integer',
    ];
}
