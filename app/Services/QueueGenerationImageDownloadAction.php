<?php

namespace App\Services;

use App\Jobs\DownloadGenerationImage;
use App\Models\Generation;

class QueueGenerationImageDownloadAction
{
    public function handle(Generation $generation): void
    {
        if (
            $generation->status !== 'succeeded'
            || blank($generation->image_url)
            || $generation->image_path !== null
        ) {
            return;
        }

        DownloadGenerationImage::dispatch((int) $generation->getKey());
    }
}
