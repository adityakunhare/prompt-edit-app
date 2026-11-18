<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GenerationImageController extends Controller
{
    public function __invoke(Request $request, Generation $generation): StreamedResponse
    {
        $generation = $request->user()
            ->generations()
            ->whereKey($generation->getKey())
            ->whereNotNull('image_path')
            ->firstOrFail();
        $disk = Storage::disk((string) config('services.pixelforge.image_disk', 'local'));

        abort_unless($disk->exists($generation->image_path), 404);

        return $disk->response($generation->image_path, headers: [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
