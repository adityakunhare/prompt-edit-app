<?php

use App\Models\Generation;
use App\Models\PixelforgeWebhookEvent;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

test('valid signatures update a generation without a CSRF token', function () {
    configurePixelforgeWebhook();
    $generation = Generation::factory()->create([
        'pixelforge_id' => 'gen_webhook123',
        'status' => 'processing',
    ]);

    $response = sendPixelforgeWebhook($this, pixelforgeWebhookEvent([
        'id' => 'evt_succeeded123',
        'data' => [
            'id' => 'gen_webhook123',
            'status' => 'succeeded',
            'image_url' => 'https://images.pixelforge.test/gen_webhook123.png',
            'image_url_expires_at' => '2026-11-19T12:00:00Z',
            'completed_at' => '2026-11-18T12:00:00Z',
        ],
    ]));

    $response->assertAccepted();

    expect($generation->refresh()->status)->toBe('succeeded');
    expect($generation->image_url)->toBe('https://images.pixelforge.test/gen_webhook123.png');
    expect($generation->image_url_expires_at?->toIso8601String())->toBe('2026-11-19T12:00:00+00:00');
    expect(PixelforgeWebhookEvent::query()->sole()->processed_at)->not->toBeNull();
});

test('invalid signatures are rejected without recording or applying the event', function () {
    configurePixelforgeWebhook();
    $generation = Generation::factory()->create([
        'pixelforge_id' => 'gen_webhook123',
        'status' => 'processing',
    ]);

    $response = sendPixelforgeWebhook(
        $this,
        pixelforgeWebhookEvent([
            'id' => 'evt_invalid123',
            'data' => ['id' => 'gen_webhook123', 'status' => 'succeeded'],
        ]),
        signature: str_repeat('0', 64),
    );

    $response->assertUnauthorized();

    expect($generation->refresh()->status)->toBe('processing');
    $this->assertDatabaseCount('pixelforge_webhook_events', 0);
});

test('timestamps outside the 300-second tolerance are rejected', function () {
    configurePixelforgeWebhook();
    $this->travelTo(now()->setDate(2026, 11, 18)->setTime(12, 0));

    $response = sendPixelforgeWebhook(
        $this,
        pixelforgeWebhookEvent([
            'id' => 'evt_expired123',
            'data' => ['id' => 'gen_missing123', 'status' => 'succeeded'],
        ]),
        timestamp: now()->subSeconds(301)->timestamp,
    );

    $response->assertUnauthorized();
    $this->assertDatabaseCount('pixelforge_webhook_events', 0);
});

test('duplicate event IDs are processed only once', function () {
    configurePixelforgeWebhook();
    $generation = Generation::factory()->create([
        'pixelforge_id' => 'gen_webhook123',
        'status' => 'queued',
    ]);

    sendPixelforgeWebhook($this, pixelforgeWebhookEvent([
        'id' => 'evt_duplicate123',
        'data' => ['id' => 'gen_webhook123', 'status' => 'processing'],
    ]))->assertAccepted();

    sendPixelforgeWebhook($this, pixelforgeWebhookEvent([
        'id' => 'evt_duplicate123',
        'data' => ['id' => 'gen_webhook123', 'status' => 'failed'],
    ]))->assertOk();

    expect($generation->refresh()->status)->toBe('processing');
    $this->assertDatabaseCount('pixelforge_webhook_events', 1);
});

test('out-of-order events do not move a terminal generation back to an active status', function () {
    configurePixelforgeWebhook();
    $generation = Generation::factory()->create([
        'pixelforge_id' => 'gen_webhook123',
        'status' => 'succeeded',
        'image_url' => 'https://images.pixelforge.test/gen_webhook123.png',
    ]);

    sendPixelforgeWebhook($this, pixelforgeWebhookEvent([
        'id' => 'evt_stale123',
        'data' => ['id' => 'gen_webhook123', 'status' => 'processing'],
    ]))->assertAccepted();

    expect($generation->refresh()->status)->toBe('succeeded');
    expect($generation->image_url)->toBe('https://images.pixelforge.test/gen_webhook123.png');
});

test('events received before generation creation are reconciled after the create response', function () {
    configurePixelforgeWebhook();
    $user = User::factory()->create();
    $event = pixelforgeWebhookEvent([
        'id' => 'evt_early123',
        'data' => [
            'id' => 'gen_created_later',
            'status' => 'succeeded',
            'image_url' => 'https://images.pixelforge.test/gen_created_later.png',
            'completed_at' => '2026-11-18T12:00:00Z',
        ],
    ]);

    sendPixelforgeWebhook($this, $event)->assertAccepted();

    config([
        'services.pixelforge.api_key' => 'test-api-key',
        'services.pixelforge.base_url' => 'https://api.pixelforge.test/v1',
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'https://api.pixelforge.test/v1/generations' => Http::response([
            'id' => 'gen_created_later',
            'status' => 'queued',
        ], 202),
    ]);

    $this
        ->actingAs($user)
        ->from(route('dashboard', absolute: false))
        ->post(route('generations.store'), [
            'idempotency_key' => 'early-webhook-submission',
            'prompt' => 'A lighthouse at dawn',
        ])
        ->assertRedirect(route('dashboard', absolute: false));

    $generation = $user->generations()->sole();

    expect($generation->status)->toBe('succeeded');
    expect($generation->image_url)->toBe('https://images.pixelforge.test/gen_created_later.png');
    expect(PixelforgeWebhookEvent::query()->sole()->processed_at)->not->toBeNull();
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.pixelforge.test/v1/generations');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pixelforgeWebhookEvent(array $overrides = []): array
{
    return [
        'id' => 'evt_default123',
        'type' => 'generation.updated',
        'data' => [
            'id' => 'gen_default123',
            'status' => 'processing',
        ],
        ...$overrides,
    ];
}

function configurePixelforgeWebhook(): void
{
    config(['services.pixelforge.webhook_secret' => 'test-webhook-secret']);
}

/**
 * @param  array<string, mixed>  $event
 */
function sendPixelforgeWebhook(
    TestCase $test,
    array $event,
    ?string $signature = null,
    ?int $timestamp = null,
): TestResponse {
    $body = json_encode($event, JSON_THROW_ON_ERROR);
    $timestamp ??= now()->timestamp;
    $signature ??= hash_hmac('sha256', $body, 'test-webhook-secret');

    return $test->call('POST', '/api/webhooks/pixelforge', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_PIXELFORGE_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_PIXELFORGE_SIGNATURE' => $signature,
    ], $body);
}
