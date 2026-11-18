<?php

use App\Jobs\DownloadGenerationImage;
use App\Models\Generation;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

test('successful image downloads are stored and associated with their generation', function () {
    configureGenerationImageStorage();
    Storage::fake('local');
    $imageContents = generationImageContents();

    Http::preventStrayRequests();
    Http::fake([
        'https://images.pixelforge.test/*' => Http::response($imageContents, 200, [
            'Content-Type' => 'image/png',
        ]),
    ]);

    $generation = Generation::factory()->create([
        'status' => 'succeeded',
        'image_url' => 'https://images.pixelforge.test/generation.png?signature=valid',
    ]);

    (new DownloadGenerationImage($generation->id))->handle();

    $imagePath = "generations/{$generation->user_id}/{$generation->id}/image.png";

    expect($generation->refresh()->image_path)->toBe($imagePath);
    expect(Storage::disk('local')->get($imagePath))->toBe($imageContents);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://images.pixelforge.test/generation.png?signature=valid');
});

test('temporary download failures leave the successful generation result intact', function () {
    configureGenerationImageStorage();
    Storage::fake('local');
    Http::preventStrayRequests();
    Http::fake([
        'https://images.pixelforge.test/*' => Http::response('temporarily unavailable', 503),
    ]);

    $generation = Generation::factory()->create([
        'status' => 'succeeded',
        'image_url' => 'https://images.pixelforge.test/generation.png',
    ]);

    expect(fn () => (new DownloadGenerationImage($generation->id))->handle())
        ->toThrow(RequestException::class);

    expect($generation->refresh()->status)->toBe('succeeded');
    expect($generation->image_url)->toBe('https://images.pixelforge.test/generation.png');
    expect($generation->image_path)->toBeNull();
});

test('image URLs outside the configured host allowlist are not requested', function () {
    configureGenerationImageStorage();
    Storage::fake('local');
    Http::preventStrayRequests();

    $generation = Generation::factory()->create([
        'status' => 'succeeded',
        'image_url' => 'https://untrusted.example/image.png',
    ]);

    (new DownloadGenerationImage($generation->id))->handle();

    expect($generation->refresh()->image_path)->toBeNull();
    Http::assertNothingSent();
});

function configureGenerationImageStorage(): void
{
    config([
        'services.pixelforge.image_disk' => 'local',
        'services.pixelforge.image_hosts' => ['images.pixelforge.test'],
    ]);
}

function generationImageContents(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/OnoAAAAASUVORK5CYII=', true) ?: '';
}
