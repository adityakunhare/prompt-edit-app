<?php

use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('archived images are served to their owner from private storage', function () {
    Storage::fake('local');
    config(['services.pixelforge.image_disk' => 'local']);
    $user = User::factory()->create();
    $imageContents = generationImageContentsForResponse();
    $generation = Generation::factory()->for($user)->create([
        'status' => 'succeeded',
    ]);

    $imagePath = "generations/{$user->id}/{$generation->id}/image.png";
    Storage::disk('local')->put($imagePath, $imageContents);
    $generation->update(['image_path' => $imagePath]);

    $response = $this
        ->actingAs($user)
        ->get(route('generations.image', $generation));

    $response
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertStreamedContent($imageContents);
});

test('users cannot view another users archived image', function () {
    Storage::fake('local');
    config(['services.pixelforge.image_disk' => 'local']);
    $generation = Generation::factory()->create([
        'image_path' => 'generations/private/image.png',
        'status' => 'succeeded',
    ]);
    $imageContents = generationImageContentsForResponse();
    Storage::disk('local')->put($generation->image_path, $imageContents);

    $response = $this
        ->actingAs(User::factory()->create())
        ->get(route('generations.image', $generation));

    $response->assertNotFound();
});

test('generation history prefers an archived image over its expiring provider URL', function () {
    Storage::fake('local');
    config(['services.pixelforge.image_disk' => 'local']);
    $user = User::factory()->create();
    $generation = Generation::factory()->for($user)->create([
        'status' => 'succeeded',
        'image_url' => 'https://images.pixelforge.test/generation.png',
        'image_url_expires_at' => '2026-11-19T12:00:00Z',
        'image_path' => "generations/{$user->id}/archived.png",
    ]);

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('generations.0.id', $generation->id)
        ->where('generations.0.image_url', route('generations.image', $generation))
        ->where('generations.0.image_is_archived', true)
    );
});

test('private local storage grants group access without granting access to other users', function () {
    expect(config('filesystems.disks.local.permissions.file.private'))->toBe(0640);
    expect(config('filesystems.disks.local.permissions.dir.private'))->toBe(0770);
});

function generationImageContentsForResponse(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/OnoAAAAASUVORK5CYII=', true) ?: '';
}
