<?php

use App\Jobs\DownloadGenerationImage;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to login when opening generation history', function () {
    $response = $this->get(route('dashboard'));

    $response->assertRedirect(route('login'));
});

test('users with no generations see empty history', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('generations', 0)
    );
});

test('users see only their own generations with statuses and results in newest-first order', function () {
    $user = User::factory()->create();
    $anotherUser = User::factory()->create();

    $queuedGeneration = Generation::factory()->for($user)->create([
        'status' => 'queued',
        'prompt' => 'A lighthouse at dawn',
        'created_at' => now()->subMinutes(4),
    ]);
    $processingGeneration = Generation::factory()->for($user)->create([
        'status' => 'processing',
        'prompt' => 'A mountain in winter',
        'created_at' => now()->subMinutes(3),
    ]);
    $succeededGeneration = Generation::factory()->for($user)->create([
        'status' => 'succeeded',
        'prompt' => 'A city at night',
        'image_url' => 'https://images.pixelforge.test/city.png',
        'image_url_expires_at' => '2026-11-19 12:00:00',
        'created_at' => now()->subMinutes(2),
    ]);
    $failedGeneration = Generation::factory()->for($user)->create([
        'status' => 'failed',
        'prompt' => 'An underwater garden',
        'error' => ['code' => 'content_policy_violation', 'message' => 'The prompt was rejected.'],
        'created_at' => now()->subMinute(),
    ]);
    Generation::factory()->for($anotherUser)->create([
        'status' => 'succeeded',
        'prompt' => 'Private image from another user',
    ]);

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('generations', 4)
        ->where('generations.0.id', $failedGeneration->id)
        ->where('generations.0.status', 'failed')
        ->where('generations.0.error.message', 'The prompt was rejected.')
        ->where('generations.1.id', $succeededGeneration->id)
        ->where('generations.1.image_url', 'https://images.pixelforge.test/city.png')
        ->where('generations.1.image_url_expires_at', '2026-11-19T12:00:00.000000Z')
        ->where('generations.2.id', $processingGeneration->id)
        ->where('generations.2.status', 'processing')
        ->where('generations.3.id', $queuedGeneration->id)
        ->where('generations.3.status', 'queued')
        ->missing('generations.0.idempotency_key')
    );
});

test('active generations refresh from Pixelforge and persist their image result', function () {
    $user = User::factory()->create();
    $generation = Generation::factory()->for($user)->create([
        'pixelforge_id' => 'gen_test123',
        'status' => 'queued',
    ]);
    Queue::fake();

    fakePixelforgeGenerationRefresh([
        'id' => 'gen_test123',
        'status' => 'succeeded',
        'image_url' => 'https://images.pixelforge.test/gen_test123.png',
        'image_url_expires_at' => '2026-11-19T12:00:00Z',
        'error' => null,
        'completed_at' => '2026-11-18T12:00:00Z',
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson(route('generations.refresh', $generation));

    $response
        ->assertOk()
        ->assertJsonPath('generation.id', $generation->id)
        ->assertJsonPath('generation.status', 'succeeded')
        ->assertJsonPath('generation.image_url', 'https://images.pixelforge.test/gen_test123.png')
        ->assertJsonPath('generation.image_url_expires_at', '2026-11-19T12:00:00.000000Z');

    expect($generation->refresh()->status)->toBe('succeeded');
    expect($generation->image_url)->toBe('https://images.pixelforge.test/gen_test123.png');
    expect($generation->completed_at?->toIso8601String())->toBe('2026-11-18T12:00:00+00:00');
    Queue::assertPushed(DownloadGenerationImage::class, fn (DownloadGenerationImage $job): bool => $job->generationId === $generation->id);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api.pixelforge.test/v1/generations/gen_test123'
        && $request->hasHeader('Authorization', 'Bearer test-api-key')
    );
});

test('failed Pixelforge refreshes store a useful generation error', function () {
    $user = User::factory()->create();
    $generation = Generation::factory()->for($user)->create([
        'pixelforge_id' => 'gen_failed123',
        'status' => 'processing',
    ]);
    $providerError = [
        'code' => 'content_policy_violation',
        'message' => 'The requested image could not be generated.',
    ];

    fakePixelforgeGenerationRefresh([
        'id' => 'gen_failed123',
        'status' => 'failed',
        'error' => $providerError,
        'completed_at' => '2026-11-18T12:00:00Z',
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson(route('generations.refresh', $generation));

    $response
        ->assertOk()
        ->assertJsonPath('generation.status', 'failed')
        ->assertJsonPath('generation.error.message', $providerError['message']);

    expect($generation->refresh()->error)->toBe($providerError);

    Http::assertSentCount(1);
});

test('users cannot refresh another users generation', function () {
    $user = User::factory()->create();
    $anotherUsersGeneration = Generation::factory()->create();

    config([
        'services.pixelforge.api_key' => 'test-api-key',
        'services.pixelforge.base_url' => 'https://api.pixelforge.test/v1',
    ]);
    Http::preventStrayRequests();

    $response = $this
        ->actingAs($user)
        ->getJson(route('generations.refresh', $anotherUsersGeneration));

    $response->assertNotFound();
    Http::assertNothingSent();
});

test('terminal generations are not refreshed from Pixelforge again', function () {
    $user = User::factory()->create();
    $generation = Generation::factory()->for($user)->create([
        'status' => 'succeeded',
        'image_url' => 'https://images.pixelforge.test/already-finished.png',
    ]);

    config([
        'services.pixelforge.api_key' => 'test-api-key',
        'services.pixelforge.base_url' => 'https://api.pixelforge.test/v1',
    ]);
    Http::preventStrayRequests();

    $response = $this
        ->actingAs($user)
        ->getJson(route('generations.refresh', $generation));

    $response
        ->assertOk()
        ->assertJsonPath('generation.status', 'succeeded')
        ->assertJsonPath('generation.image_url', 'https://images.pixelforge.test/already-finished.png');

    Http::assertNothingSent();
});

/**
 * @param  array<string, mixed>  $response
 */
function fakePixelforgeGenerationRefresh(array $response): void
{
    config([
        'services.pixelforge.api_key' => 'test-api-key',
        'services.pixelforge.base_url' => 'https://api.pixelforge.test/v1',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'https://api.pixelforge.test/v1/generations/*' => Http::response($response),
    ]);
}
