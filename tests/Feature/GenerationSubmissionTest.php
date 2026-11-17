<?php

use App\Models\Generation;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('guests are redirected to login without calling Pixelforge', function () {
    fakePixelforgeGenerationSubmission(['id' => 'gen_test123', 'status' => 'queued']);

    $response = $this
        ->from(route('dashboard', absolute: false))
        ->post(route('generations.store'), validPixelforgeGenerationSubmission());

    $response->assertRedirect(route('login', absolute: false));
    Http::assertNothingSent();
    $this->assertDatabaseCount('generations', 0);
});

test('authenticated users can submit a generation and store the queued result', function () {
    $user = User::factory()->create();
    $payload = validPixelforgeGenerationSubmission();

    fakePixelforgeGenerationSubmission([
        'id' => 'gen_test123',
        'status' => 'queued',
    ]);

    $response = $this
        ->actingAs($user)
        ->from(route('dashboard', absolute: false))
        ->post(route('generations.store'), $payload);

    $response
        ->assertRedirect(route('dashboard', absolute: false))
        ->assertInertiaFlash('status', 'generation_queued');

    $generation = Generation::query()->sole();

    $this->assertModelExists($generation);
    expect($generation->user_id)->toBe($user->id);
    expect($generation->pixelforge_id)->toBe('gen_test123');
    expect($generation->status)->toBe('queued');
    expect($generation->prompt)->toBe($payload['prompt']);
    expect($generation->size)->toBe($payload['size']);
    expect($generation->quality)->toBe($payload['quality']);
    expect($generation->reference_images)->toBe($payload['reference_images']);
    expect($generation->metadata)->toBe($payload['metadata']);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.pixelforge.test/v1/generations'
        && $request->hasHeader(
            'Idempotency-Key',
            hash('sha256', $user->id.':'.$payload['idempotency_key']),
        )
        && $request->data() === [
            'prompt' => $payload['prompt'],
            'size' => $payload['size'],
            'quality' => $payload['quality'],
            'reference_images' => $payload['reference_images'],
            'metadata' => $payload['metadata'],
        ]
    );
});

test('default settings are applied when optional generation parameters are omitted', function () {
    $user = User::factory()->create();
    $payload = validPixelforgeGenerationSubmission();
    unset($payload['size'], $payload['quality'], $payload['reference_images'], $payload['metadata']);

    fakePixelforgeGenerationSubmission([
        'id' => 'gen_test123',
        'status' => 'queued',
    ]);

    $this
        ->actingAs($user)
        ->from(route('dashboard', absolute: false))
        ->post(route('generations.store'), $payload)
        ->assertRedirect(route('dashboard', absolute: false));

    $generation = $user->generations()->sole();

    expect($generation->size)->toBe('1024x1024');
    expect($generation->quality)->toBe('medium');
    expect($generation->reference_images)->toBe([]);
    expect($generation->metadata)->toBe([]);

    Http::assertSent(fn (Request $request): bool => $request->data() === [
        'prompt' => $payload['prompt'],
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => [],
        'metadata' => [],
    ]);
});

test('the idempotency key may be supplied in the standard request header', function () {
    $user = User::factory()->create();
    $payload = validPixelforgeGenerationSubmission();
    unset($payload['idempotency_key']);

    fakePixelforgeGenerationSubmission([
        'id' => 'gen_test123',
        'status' => 'queued',
    ]);

    $this
        ->actingAs($user)
        ->withHeader('Idempotency-Key', 'header-submission-1042')
        ->from(route('dashboard', absolute: false))
        ->post(route('generations.store'), $payload)
        ->assertRedirect(route('dashboard', absolute: false));

    expect($user->generations()->sole()->idempotency_key)->toBe('header-submission-1042');
    Http::assertSent(fn (Request $request): bool => $request->hasHeader(
        'Idempotency-Key',
        hash('sha256', $user->id.':header-submission-1042'),
    ));
});

test('invalid generation parameters are rejected before calling Pixelforge', function () {
    $user = User::factory()->create();
    $payload = validPixelforgeGenerationSubmission();
    $payload['prompt'] = str_repeat('p', 4001);
    $payload['size'] = '512x512';
    $payload['quality'] = 'ultra';
    $payload['reference_images'] = array_map(
        fn (int $index): string => "https://example.com/reference-{$index}.png",
        range(1, 5),
    );
    $payload['metadata'] = array_fill_keys(
        array_map(fn (int $index): string => "field_{$index}", range(1, 20)),
        'value',
    );
    $payload['metadata']['too_long'] = str_repeat('v', 501);

    fakePixelforgeGenerationSubmission(['id' => 'gen_test123', 'status' => 'queued']);

    $response = $this
        ->actingAs($user)
        ->from(route('dashboard', absolute: false))
        ->post(route('generations.store'), $payload);

    $response->assertSessionHasErrors([
        'prompt',
        'size',
        'quality',
        'reference_images',
        'metadata',
        'metadata.too_long',
    ]);

    Http::assertNothingSent();
    $this->assertDatabaseCount('generations', 0);
});

test('metadata lists are rejected before calling Pixelforge', function () {
    $user = User::factory()->create();
    $payload = validPixelforgeGenerationSubmission();
    $payload['metadata'] = ['first value', 'second value'];

    fakePixelforgeGenerationSubmission(['id' => 'gen_test123', 'status' => 'queued']);

    $response = $this
        ->actingAs($user)
        ->from(route('dashboard', absolute: false))
        ->post(route('generations.store'), $payload);

    $response->assertSessionHasErrors('metadata');
    Http::assertNothingSent();
    $this->assertDatabaseCount('generations', 0);
});

test('repeating a submission with the same idempotency key reuses its generation', function () {
    $user = User::factory()->create();
    $payload = validPixelforgeGenerationSubmission();

    fakePixelforgeGenerationSubmission([
        'id' => 'gen_test123',
        'status' => 'queued',
    ]);

    $firstResponse = $this
        ->actingAs($user)
        ->from(route('dashboard', absolute: false))
        ->post(route('generations.store'), $payload);

    $secondResponse = $this
        ->from(route('dashboard', absolute: false))
        ->post(route('generations.store'), $payload);

    $firstResponse->assertRedirect(route('dashboard', absolute: false));
    $secondResponse->assertRedirect(route('dashboard', absolute: false));
    Http::assertSentCount(1);
    $this->assertDatabaseCount('generations', 1);
});

test('provider idempotency keys are isolated between app users', function () {
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();
    $payload = validPixelforgeGenerationSubmission();

    config([
        'services.pixelforge.api_key' => 'test-api-key',
        'services.pixelforge.base_url' => 'https://api.pixelforge.test/v1',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'https://api.pixelforge.test/v1/generations' => Http::sequence()
            ->push(['id' => 'gen_firstuser', 'status' => 'queued'], 202)
            ->push(['id' => 'gen_seconduser', 'status' => 'queued'], 202),
    ]);

    $this
        ->actingAs($firstUser)
        ->from(route('dashboard', absolute: false))
        ->post(route('generations.store'), $payload);

    $this
        ->actingAs($secondUser)
        ->from(route('dashboard', absolute: false))
        ->post(route('generations.store'), $payload);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader(
        'Idempotency-Key',
        hash('sha256', $firstUser->id.':'.$payload['idempotency_key']),
    ));
    Http::assertSent(fn (Request $request): bool => $request->hasHeader(
        'Idempotency-Key',
        hash('sha256', $secondUser->id.':'.$payload['idempotency_key']),
    ));

    expect($firstUser->generations()->sole()->pixelforge_id)->toBe('gen_firstuser');
    expect($secondUser->generations()->sole()->pixelforge_id)->toBe('gen_seconduser');
});

test('an idempotency key cannot be reused with different generation parameters', function () {
    $user = User::factory()->create();
    $payload = validPixelforgeGenerationSubmission();

    fakePixelforgeGenerationSubmission([
        'id' => 'gen_test123',
        'status' => 'queued',
    ]);

    $this
        ->actingAs($user)
        ->from(route('dashboard', absolute: false))
        ->post(route('generations.store'), $payload);

    $payload['prompt'] = 'A different lighthouse';

    $response = $this
        ->from(route('dashboard', absolute: false))
        ->post(route('generations.store'), $payload);

    $response->assertSessionHasErrors('idempotency_key');
    Http::assertSentCount(1);
    $this->assertDatabaseCount('generations', 1);
});

test('provider validation details are surfaced when Pixelforge returns 422', function () {
    $user = User::factory()->create();
    $providerError = [
        'type' => 'invalid_request_error',
        'code' => 'validation_failed',
        'message' => 'The selected size is invalid.',
        'param' => 'size',
        'errors' => ['size' => ['The selected size is invalid.']],
    ];

    fakePixelforgeGenerationSubmission(['error' => $providerError], 422);

    $response = $this
        ->actingAs($user)
        ->from(route('dashboard', absolute: false))
        ->post(route('generations.store'), validPixelforgeGenerationSubmission());

    $response
        ->assertRedirect(route('dashboard', absolute: false))
        ->assertSessionHasErrors(['size' => 'The selected size is invalid.'])
        ->assertInertiaFlash('pixelforge_error', $providerError + ['status' => 422]);

    $this->assertDatabaseCount('generations', 0);
});

test('content policy errors are surfaced when Pixelforge returns 400', function () {
    $user = User::factory()->create();
    $providerError = [
        'type' => 'invalid_request_error',
        'code' => 'content_policy_violation',
        'message' => 'The prompt was rejected by the content policy.',
        'param' => 'prompt',
    ];

    fakePixelforgeGenerationSubmission(['error' => $providerError], 400);

    $response = $this
        ->actingAs($user)
        ->from(route('dashboard', absolute: false))
        ->post(route('generations.store'), validPixelforgeGenerationSubmission());

    $response
        ->assertRedirect(route('dashboard', absolute: false))
        ->assertSessionHasErrors(['prompt' => $providerError['message']])
        ->assertInertiaFlash('pixelforge_error', $providerError + ['status' => 400]);

    $this->assertDatabaseCount('generations', 0);
});

test('rate limit details are surfaced when Pixelforge returns 429', function () {
    $user = User::factory()->create();
    $providerError = [
        'type' => 'rate_limit_error',
        'code' => 'rate_limit_exceeded',
        'message' => 'Too many requests this minute.',
    ];

    fakePixelforgeGenerationSubmission(['error' => $providerError], 429, ['Retry-After' => '17']);

    $response = $this
        ->actingAs($user)
        ->from(route('dashboard', absolute: false))
        ->post(route('generations.store'), validPixelforgeGenerationSubmission());

    $response
        ->assertRedirect(route('dashboard', absolute: false))
        ->assertSessionHasErrors(['prompt' => $providerError['message']])
        ->assertInertiaFlash('pixelforge_error', $providerError + ['status' => 429, 'retry_after' => '17']);

    $this->assertDatabaseCount('generations', 0);
});

test('overload details are surfaced when Pixelforge returns 503', function () {
    $user = User::factory()->create();
    $providerError = [
        'type' => 'api_error',
        'code' => 'overloaded',
        'message' => 'Pixelforge is temporarily overloaded.',
    ];

    fakePixelforgeGenerationSubmission(['error' => $providerError], 503, ['Retry-After' => '2']);

    $response = $this
        ->actingAs($user)
        ->from(route('dashboard', absolute: false))
        ->post(route('generations.store'), validPixelforgeGenerationSubmission());

    $response
        ->assertRedirect(route('dashboard', absolute: false))
        ->assertSessionHasErrors(['prompt' => $providerError['message']])
        ->assertInertiaFlash('pixelforge_error', $providerError + ['status' => 503, 'retry_after' => '2']);

    $this->assertDatabaseCount('generations', 0);
});

/**
 * @param  array<string, mixed>  $response
 * @param  array<string, string>  $headers
 */
function fakePixelforgeGenerationSubmission(array $response, int $status = 202, array $headers = []): void
{
    config([
        'services.pixelforge.api_key' => 'test-api-key',
        'services.pixelforge.base_url' => 'https://api.pixelforge.test/v1',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'https://api.pixelforge.test/v1/generations' => Http::response($response, $status, $headers),
    ]);
}

/**
 * @return array{
 *     idempotency_key: string,
 *     prompt: string,
 *     size: string,
 *     quality: string,
 *     reference_images: list<string>,
 *     metadata: array<string, string>
 * }
 */
function validPixelforgeGenerationSubmission(): array
{
    return [
        'idempotency_key' => 'submission-1042',
        'prompt' => 'A lighthouse at dawn',
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/style.png'],
        'metadata' => ['order_id' => '1042'],
    ];
}
