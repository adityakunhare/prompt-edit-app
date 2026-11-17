<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class PixelforgeClient
{
    /**
     * @param array{
     *     prompt: string,
     *     size?: string,
     *     quality?: string,
     *     reference_images?: list<string>,
     *     metadata?: array<string, string>
     * } $payload
     * @return array<string, mixed>
     */
    public function createGeneration(array $payload, string $idempotencyKey): array
    {
        return $this->request()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('generations', $payload)
            ->throw()
            ->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function getGeneration(string $id): array
    {
        return $this->request()
            ->get('generations/'.rawurlencode($id))
            ->throw()
            ->json();
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.pixelforge.base_url'), '/'))
            ->acceptJson()
            ->asJson()
            ->withToken((string) config('services.pixelforge.api_key'))
            ->timeout((int) config('services.pixelforge.timeout', 30));
    }
}
