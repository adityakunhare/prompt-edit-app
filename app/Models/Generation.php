<?php

namespace App\Models;

use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'idempotency_key',
    'pixelforge_id',
    'status',
    'prompt',
    'size',
    'quality',
    'reference_images',
    'metadata',
    'image_url',
    'image_url_expires_at',
    'usage',
    'rates',
    'error',
    'pixelforge_created_at',
    'started_at',
    'completed_at',
])]
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reference_images' => 'array',
            'metadata' => 'array',
            'image_url_expires_at' => 'datetime',
            'usage' => 'array',
            'rates' => 'array',
            'error' => 'array',
            'pixelforge_created_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
