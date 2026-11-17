<?php

use App\Services\PixelforgeClient;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

it('creates a generation with the configured API credentials and idempotency key', function () {
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

    $payload = [
        'prompt' => 'A lighthouse at dawn',
        'size' => '1536x1024',
        'quality' => 'high',
        'metadata' => ['order_id' => '1042'],
    ];
    $generation = app(PixelforgeClient::class)->createGeneration($payload, 'submission-1042');

    expect($generation)->toBe([
        'id' => 'gen_test123',
        'status' => 'queued',
    ]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.pixelforge.test/v1/generations'
        && $request->hasHeader('Authorization', 'Bearer test-api-key')
        && $request->hasHeader('Content-Type', 'application/json')
        && $request->hasHeader('Idempotency-Key', 'submission-1042')
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
});
