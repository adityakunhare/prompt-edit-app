<?php

namespace App\Services;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class SubmitGenerationAction
{
    /**
     * Create a new action instance.
     */
    public function __construct(
        private PixelforgeClient $pixelforgeClient,
        private ProcessPixelforgeWebhookAction $processWebhook,
    ) {}

    /**
     * @param array{
     *     idempotency_key: string,
     *     prompt: string,
     *     size?: string,
     *     quality?: string,
     *     reference_images?: list<string>,
     *     metadata?: array<string, string>
     * } $data
     */
    public function handle(User $user, array $data): Generation
    {
        $idempotencyKey = $data['idempotency_key'];
        $parameters = $this->parameters($data);
        $existingGeneration = $user->generations()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existingGeneration !== null) {
            return $this->reconcilePendingWebhooks(
                $this->reuseExistingGeneration($existingGeneration, $parameters),
            );
        }

        $providerIdempotencyKey = hash('sha256', $user->getAuthIdentifier().':'.$idempotencyKey);
        $providerGeneration = $this->pixelforgeClient->createGeneration($parameters, $providerIdempotencyKey);

        $attributes = [
            ...$parameters,
            'idempotency_key' => $idempotencyKey,
            'pixelforge_id' => $providerGeneration['id'],
            'status' => $providerGeneration['status'],
            'image_url' => $providerGeneration['image_url'] ?? null,
            'image_url_expires_at' => $providerGeneration['image_url_expires_at'] ?? null,
            'usage' => $providerGeneration['usage'] ?? null,
            'rates' => $providerGeneration['rates'] ?? null,
            'error' => $providerGeneration['error'] ?? null,
            'pixelforge_created_at' => $providerGeneration['created_at'] ?? null,
            'started_at' => $providerGeneration['started_at'] ?? null,
            'completed_at' => $providerGeneration['completed_at'] ?? null,
        ];

        try {
            $generation = $user->generations()->create($attributes);
        } catch (QueryException $exception) {
            $existingGeneration = $user->generations()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingGeneration === null) {
                throw $exception;
            }

            $generation = $this->reuseExistingGeneration($existingGeneration, $parameters);
        }

        return $this->reconcilePendingWebhooks($generation);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     prompt: string,
     *     size: string,
     *     quality: string,
     *     reference_images: list<string>,
     *     metadata: array<string, string>
     * }
     */
    private function parameters(array $data): array
    {
        return [
            'prompt' => $data['prompt'],
            'size' => $data['size'] ?? '1024x1024',
            'quality' => $data['quality'] ?? 'medium',
            'reference_images' => $data['reference_images'] ?? [],
            'metadata' => $data['metadata'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function reuseExistingGeneration(Generation $generation, array $parameters): Generation
    {
        if ($generation->only(array_keys($parameters)) !== $parameters) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'This idempotency key has already been used with different generation parameters.',
            ]);
        }

        return $generation;
    }

    private function reconcilePendingWebhooks(Generation $generation): Generation
    {
        if ($generation->pixelforge_id !== null) {
            $this->processWebhook->reconcile($generation->pixelforge_id);
        }

        return $generation->refresh();
    }
}
