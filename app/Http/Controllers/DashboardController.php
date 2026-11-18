<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Services\PixelforgeClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    private const PRICING_CACHE_MINUTES = 5;

    public function __invoke(Request $request, PixelforgeClient $pixelforgeClient): Response
    {
        [$startDate, $endDate] = $this->usageDateRange($request);

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

        try {
            $pricing = Cache::remember(
                'pixelforge.pricing',
                now()->addMinutes(self::PRICING_CACHE_MINUTES),
                fn (): array => $pixelforgeClient->getPricing(),
            );
        } catch (ConnectionException|RequestException $exception) {
            report($exception);
            $pricing = null;
        }

        try {
            $usage = $pixelforgeClient->getUsage($startDate, $endDate);
        } catch (ConnectionException|RequestException $exception) {
            report($exception);
            $usage = null;
        }

        return Inertia::render('Dashboard', [
            'generations' => $generations,
            'pricing' => $pricing,
            'usage' => $usage,
            'usageStartDate' => $startDate,
            'usageEndDate' => $endDate,
            'activeTab' => $request->query('tab') === 'pricing' ? 'pricing' : 'generation',
        ]);
    }

    /**
     * @return array{string, string}
     */
    private function usageDateRange(Request $request): array
    {
        $dates = $request->validate([
            'start_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
        ]);

        $today = CarbonImmutable::now('UTC');
        $startDate = $dates['start_date'] ?? $today->subDays(29)->toDateString();
        $endDate = $dates['end_date'] ?? $today->toDateString();
        $start = CarbonImmutable::parse($startDate, 'UTC');
        $end = CarbonImmutable::parse($endDate, 'UTC');

        if ($end->isBefore($start)) {
            throw ValidationException::withMessages([
                'end_date' => 'The end date must be on or after the start date.',
            ]);
        }

        if ($start->diff($end)->days > 91) {
            throw ValidationException::withMessages([
                'start_date' => 'The selected date range may not exceed 92 days.',
            ]);
        }

        return [$startDate, $endDate];
    }
}
