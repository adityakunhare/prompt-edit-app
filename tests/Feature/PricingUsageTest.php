<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

test('dashboard shows provider pricing and usage with exact decimal values', function () {
    $user = User::factory()->create();
    $usage = pixelforgeUsageReport('2026-11-01', '2026-11-02');

    fakePixelforgePricingUsage($usage);

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard', [
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-02',
            'tab' => 'pricing',
        ]));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('pricing.currency', 'usd')
        ->where('pricing.unit', 'per_1m_tokens')
        ->where('pricing.rates.input_text_tokens', '5.00')
        ->where('pricing.rates.reasoning_tokens', '12.50')
        ->where('usage.start_date', '2026-11-01')
        ->where('usage.end_date', '2026-11-02')
        ->where('usage.total.cost_usd', '0.169080000')
        ->where('usage.data.0.cost_usd', '0.169080000')
        ->where('usageStartDate', '2026-11-01')
        ->where('usageEndDate', '2026-11-02')
        ->where('activeTab', 'pricing')
    );

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->hasHeader('Authorization', 'Bearer test-api-key')
        && parse_url($request->url(), PHP_URL_PATH) === '/v1/usage'
        && usageQuery($request) === [
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-02',
        ]
    );
});

test('usage accepts the full supported 92-day inclusive range', function () {
    $user = User::factory()->create();

    fakePixelforgePricingUsage(pixelforgeUsageReport('2026-08-01', '2026-10-31'));

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard', [
            'start_date' => '2026-08-01',
            'end_date' => '2026-10-31',
        ]));

    $response->assertOk();

    Http::assertSent(fn (Request $request): bool => usageQuery($request) === [
        'start_date' => '2026-08-01',
        'end_date' => '2026-10-31',
    ]);
});

test('usage rejects date ranges longer than 92 days before calling Pixelforge', function () {
    $user = User::factory()->create();
    Http::preventStrayRequests();

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard', [
            'start_date' => '2026-07-31',
            'end_date' => '2026-10-31',
        ]));

    $response->assertSessionHasErrors('start_date');
    Http::assertNothingSent();
});

test('usage rejects an end date before the start date', function () {
    $user = User::factory()->create();
    Http::preventStrayRequests();

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard', [
            'start_date' => '2026-11-03',
            'end_date' => '2026-11-02',
        ]));

    $response->assertSessionHasErrors('end_date');
    Http::assertNothingSent();
});

test('dashboard defaults to the latest 30 UTC days and caches pricing briefly', function () {
    $user = User::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-11-18T12:00:00Z'));
    Cache::forget('pixelforge.pricing');

    fakePixelforgePricingUsage(pixelforgeUsageReport('2026-10-20', '2026-11-18'));

    $firstResponse = $this->actingAs($user)->get(route('dashboard'));
    $secondResponse = $this->get(route('dashboard'));

    $firstResponse->assertInertia(fn (Assert $page) => $page
        ->where('usageStartDate', '2026-10-20')
        ->where('usageEndDate', '2026-11-18')
    );
    $secondResponse->assertOk();

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => usageQuery($request) === [
        'start_date' => '2026-10-20',
        'end_date' => '2026-11-18',
    ]);
});

/**
 * @param  array<string, mixed>  $usage
 */
function fakePixelforgePricingUsage(array $usage): void
{
    config([
        'services.pixelforge.api_key' => 'test-api-key',
        'services.pixelforge.base_url' => 'https://api.pixelforge.test/v1',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'https://api.pixelforge.test/v1/pricing' => Http::response([
            'object' => 'pricing',
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
        ]),
        'https://api.pixelforge.test/v1/usage*' => Http::response($usage),
    ]);
}

/**
 * @return array<string, mixed>
 */
function pixelforgeUsageReport(string $startDate, string $endDate): array
{
    return [
        'object' => 'usage',
        'start_date' => $startDate,
        'end_date' => $endDate,
        'data' => [[
            'date' => $startDate,
            'generations' => 3,
            'input_text_tokens' => 41,
            'input_image_tokens' => 0,
            'output_tokens' => 3300,
            'reasoning_tokens' => 2950,
            'cost_usd' => '0.169080000',
        ]],
        'total' => [
            'generations' => 3,
            'input_text_tokens' => 41,
            'input_image_tokens' => 0,
            'output_tokens' => 3300,
            'reasoning_tokens' => 2950,
            'cost_usd' => '0.169080000',
        ],
    ];
}

/**
 * @return array<string, string>
 */
function usageQuery(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}
