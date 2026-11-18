<?php

use App\Services\PixelforgeClient;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

it('creates a generation with the configured API credentials and idempotency key', function () {
    $useRealApi = false; // Set to true to use PIXELFORGE_API_KEY from .env.

    if ($useRealApi) {
        expect(config('services.pixelforge.api_key'))->not->toBeEmpty();
    } else {
        config([
            'services.pixelforge.api_key' => 'test-api-key',
            'services.pixelforge.base_url' => 'https://api.pixelforge.test/v1',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.pixelforge.test/v1/generations' => Http::response([
                'id' => 'gen_test123',
                'status' => 'queued',
            ], 202),
        ]);
    }

    $payload = [
        'prompt' => $useRealApi
            ? '[succeed] Pixelforge client test'
            : 'A lighthouse at dawn',
        'size' => '1536x1024',
        'quality' => 'high',
        'metadata' => ['order_id' => '1042'],
    ];
    $idempotencyKey = $useRealApi
        ? 'live-client-smoke-'.Str::uuid()
        : 'submission-1042';
    $client = app(PixelforgeClient::class);
    $generation = $client->createGeneration($payload, $idempotencyKey);

    if ($useRealApi) {
        $retrievedGeneration = $client->getGeneration($generation['id']);

        expect($generation['id'])->not->toBeEmpty();
        expect($generation['status'])->toBe('queued');
        expect($retrievedGeneration['id'])->toBe($generation['id']);
        expect(in_array($retrievedGeneration['status'], ['queued', 'processing', 'succeeded'], true))->toBeTrue();

        return;
    }

    expect($generation)->toBe([
        'id' => 'gen_test123',
        'status' => 'queued',
    ]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.pixelforge.test/v1/generations'
        && $request->hasHeader('Authorization', 'Bearer test-api-key')
        && $request->hasHeader('Content-Type', 'application/json')
        && $request->hasHeader('Idempotency-Key', $idempotencyKey)
        && $request->data() === $payload
    );
});

it('retrieves a generation from its configured endpoint', function () {
    config([
        'services.pixelforge.api_key' => 'test-api-key',
        'services.pixelforge.base_url' => 'https://api.pixelforge.test/v1',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'https://api.pixelforge.test/v1/generations/gen_test123' => Http::response([
            'id' => 'gen_test123',
            'status' => 'succeeded',
            'image_url' => 'https://images.pixelforge.test/gen_test123.png',
        ]),
    ]);

    $generation = app(PixelforgeClient::class)->getGeneration('gen_test123');

    expect($generation)->toBe([
        'id' => 'gen_test123',
        'status' => 'succeeded',
        'image_url' => 'https://images.pixelforge.test/gen_test123.png',
    ]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api.pixelforge.test/v1/generations/gen_test123'
        && $request->hasHeader('Authorization', 'Bearer test-api-key')
    );
});

it('retrieves provider pricing without sending the configured API key', function () {
    config([
        'services.pixelforge.api_key' => 'test-api-key',
        'services.pixelforge.base_url' => 'https://api.pixelforge.test/v1',
    ]);

    $pricing = [
        'object' => 'pricing',
        'currency' => 'usd',
        'unit' => 'per_1m_tokens',
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
    ];

    Http::preventStrayRequests();
    Http::fake([
        'https://api.pixelforge.test/v1/pricing' => Http::response($pricing),
    ]);

    expect(app(PixelforgeClient::class)->getPricing())->toBe($pricing);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api.pixelforge.test/v1/pricing'
        && ! $request->hasHeader('Authorization')
    );
});

it('retrieves usage for the requested date range with exact provider costs', function () {
    config([
        'services.pixelforge.api_key' => 'test-api-key',
        'services.pixelforge.base_url' => 'https://api.pixelforge.test/v1',
    ]);

    $usage = [
        'object' => 'usage',
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-02',
        'data' => [[
            'date' => '2026-11-01',
            'generations' => 1,
            'input_text_tokens' => 41,
            'input_image_tokens' => 0,
            'output_tokens' => 3300,
            'reasoning_tokens' => 2950,
            'cost_usd' => '0.169080000',
        ]],
        'total' => [
            'generations' => 1,
            'input_text_tokens' => 41,
            'input_image_tokens' => 0,
            'output_tokens' => 3300,
            'reasoning_tokens' => 2950,
            'cost_usd' => '0.169080000',
        ],
    ];

    Http::preventStrayRequests();
    Http::fake([
        'https://api.pixelforge.test/v1/usage*' => Http::response($usage),
    ]);

    expect(app(PixelforgeClient::class)->getUsage('2026-11-01', '2026-11-02'))->toBe($usage);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->hasHeader('Authorization', 'Bearer test-api-key')
        && parse_url($request->url(), PHP_URL_PATH) === '/v1/usage'
        && parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query) === null
        && $query === ['start_date' => '2026-11-01', 'end_date' => '2026-11-02']
    );
});

it('preserves Pixelforge validation details when it returns 422', function () {
    config([
        'services.pixelforge.api_key' => 'test-api-key',
        'services.pixelforge.base_url' => 'https://api.pixelforge.test/v1',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'https://api.pixelforge.test/v1/generations' => Http::response([
            'error' => [
                'type' => 'invalid_request_error',
                'code' => 'validation_failed',
                'message' => 'The selected size is invalid.',
                'param' => 'size',
            ],
        ], 422),
    ]);

    $exception = null;

    try {
        app(PixelforgeClient::class)->createGeneration(['prompt' => 'A lighthouse'], 'submission-1042');
    } catch (RequestException $requestException) {
        $exception = $requestException;
    }

    expect($exception)->toBeInstanceOf(RequestException::class);
    expect($exception->response->status())->toBe(422);
    expect($exception->response->json('error.code'))->toBe('validation_failed');

    Http::assertSentCount(1);
});

it('retries a timed out generation with the same idempotency key', function () {
    config([
        'services.pixelforge.api_key' => 'test-api-key',
        'services.pixelforge.base_url' => 'https://api.pixelforge.test/v1',
    ]);

    Sleep::fake();

    $attemptedIdempotencyKeys = [];

    Http::preventStrayRequests();
    Http::fake(function (Request $request) use (&$attemptedIdempotencyKeys) {
        $attemptedIdempotencyKeys[] = $request->header('Idempotency-Key')[0] ?? null;

        if (count($attemptedIdempotencyKeys) === 1) {
            return Http::failedConnection('cURL error 28: Operation timed out')($request);
        }

        return Http::response([
            'id' => 'gen_test123',
            'status' => 'queued',
        ], 202);
    });

    $generation = app(PixelforgeClient::class)->createGeneration(
        ['prompt' => 'A lighthouse at dawn'],
        'submission-1042',
    );

    expect($generation['status'])->toBe('queued');
    expect($attemptedIdempotencyKeys)->toBe(['submission-1042', 'submission-1042']);

    Http::assertSentCount(2);
    Sleep::assertSleptTimes(1);
});

it('honors Retry-After when retrying a rate-limited generation', function () {
    config([
        'services.pixelforge.api_key' => 'test-api-key',
        'services.pixelforge.base_url' => 'https://api.pixelforge.test/v1',
    ]);

    Sleep::fake();

    $attemptedIdempotencyKeys = [];

    Http::preventStrayRequests();
    Http::fake(function (Request $request) use (&$attemptedIdempotencyKeys) {
        $attemptedIdempotencyKeys[] = $request->header('Idempotency-Key')[0] ?? null;

        if (count($attemptedIdempotencyKeys) === 1) {
            return Http::response([
                'error' => [
                    'type' => 'rate_limit_error',
                    'code' => 'rate_limit_exceeded',
                    'message' => 'Too many requests this minute.',
                ],
            ], 429, ['Retry-After' => '2']);
        }

        return Http::response([
            'id' => 'gen_test123',
            'status' => 'queued',
        ], 202);
    });

    $generation = app(PixelforgeClient::class)->createGeneration(
        ['prompt' => 'A lighthouse at dawn'],
        'submission-1042',
    );

    expect($generation['id'])->toBe('gen_test123');
    expect($attemptedIdempotencyKeys)->toBe(['submission-1042', 'submission-1042']);

    Http::assertSentCount(2);
    Sleep::assertSequence([Sleep::for(2)->seconds()]);
});

it('retries overloaded generations only up to the attempt limit and preserves the provider error', function () {
    config([
        'services.pixelforge.api_key' => 'test-api-key',
        'services.pixelforge.base_url' => 'https://api.pixelforge.test/v1',
    ]);

    Sleep::fake();

    $attemptedIdempotencyKeys = [];
    $exception = null;

    Http::preventStrayRequests();
    Http::fake(function (Request $request) use (&$attemptedIdempotencyKeys) {
        $attemptedIdempotencyKeys[] = $request->header('Idempotency-Key')[0] ?? null;

        return Http::response([
            'error' => [
                'type' => 'api_error',
                'code' => 'overloaded',
                'message' => 'Pixelforge is temporarily overloaded.',
            ],
        ], 503, ['Retry-After' => '17']);
    });

    try {
        app(PixelforgeClient::class)->createGeneration(['prompt' => 'A lighthouse at dawn'], 'submission-1042');
    } catch (RequestException $requestException) {
        $exception = $requestException;
    }

    expect($exception)->toBeInstanceOf(RequestException::class);
    expect($exception->response->status())->toBe(503);
    expect($exception->response->json('error.code'))->toBe('overloaded');
    expect($attemptedIdempotencyKeys)->toBe([
        'submission-1042',
        'submission-1042',
        'submission-1042',
    ]);

    Http::assertSentCount(3);
    Sleep::assertSequence([
        Sleep::for(5)->seconds(),
        Sleep::for(5)->seconds(),
    ]);
});
