<?php

namespace App\Http\Controllers;

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
                'error',
                'created_at',
                'completed_at',
            ]);

        return Inertia::render('Dashboard', [
            'generations' => $generations,
        ]);
    }
}
