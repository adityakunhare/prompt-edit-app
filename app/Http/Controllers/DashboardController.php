<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $generations = $request->user()
            ->generations()
            ->latest('created_at')
            ->orderByDesc('id')
            ->get([
                'id',
                'status',
                'prompt',
                'size',
                'quality',
                'image_url',
                'image_url_expires_at',
                'image_path',
                'error',
                'created_at',
                'completed_at',
            ])
            ->map(fn (Generation $generation): array => [
                'id' => $generation->id,
                'status' => $generation->status,
                'prompt' => $generation->prompt,
                'size' => $generation->size,
                'quality' => $generation->quality,
                'image_url' => $generation->image_path !== null
                    ? route('generations.image', $generation)
                    : $generation->image_url,
                'image_is_archived' => $generation->image_path !== null,
                'image_url_expires_at' => $generation->image_url_expires_at,
                'error' => $generation->error,
                'created_at' => $generation->created_at,
                'completed_at' => $generation->completed_at,
            ]);

        return Inertia::render('Dashboard', [
            'generations' => $generations,
        ]);
    }
}
