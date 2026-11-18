<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Services\PixelforgeClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GenerationRefreshController extends Controller
{
    public function __invoke(Request $request, Generation $generation, PixelforgeClient $pixelforgeClient): JsonResponse
    {
        $generation = $request->user()
            ->generations()
            ->whereKey($generation->getKey())
            ->firstOrFail();

        if (in_array($generation->status, ['queued', 'processing'], true) && $generation->pixelforge_id !== null) {
            $providerGeneration = $pixelforgeClient->getGeneration($generation->pixelforge_id);
            $attributes = [];

            if (in_array($providerGeneration['status'] ?? null, ['queued', 'processing', 'succeeded', 'failed'], true)) {
                $attributes['status'] = $providerGeneration['status'];
            }

            foreach ([
                'image_url' => 'image_url',
                'image_url_expires_at' => 'image_url_expires_at',
                'usage' => 'usage',
                'rates' => 'rates',
                'error' => 'error',
                'created_at' => 'pixelforge_created_at',
                'started_at' => 'started_at',
                'completed_at' => 'completed_at',
            ] as $providerAttribute => $generationAttribute) {
                if (array_key_exists($providerAttribute, $providerGeneration)) {
                    $attributes[$generationAttribute] = $providerGeneration[$providerAttribute];
                }
            }

            if ($attributes !== []) {
                $generation->update($attributes);
            }
        }

        return response()->json([
            'generation' => $generation->only([
                'id',
                'status',
                'prompt',
                'size',
                'quality',
                'image_url',
                'image_url_expires_at',
                'error',
                'created_at',
                'completed_at',
            ]),
        ]);
    }
}
