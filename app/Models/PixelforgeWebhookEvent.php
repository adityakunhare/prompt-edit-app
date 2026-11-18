<?php

namespace App\Models;

use Database\Factories\PixelforgeWebhookEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'event_id',
    'pixelforge_generation_id',
    'payload',
    'processed_at',
])]
class PixelforgeWebhookEvent extends Model
{
    /** @use HasFactory<PixelforgeWebhookEventFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
