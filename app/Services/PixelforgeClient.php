<?php

namespace App\Services;

use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

class PixelforgeClient
{
    private const MAX_GENERATION_ATTEMPTS = 3;

    private const MAX_RETRY_DELAY_MILLISECONDS = 5000;

    private const BASE_RETRY_DELAY_MILLISECONDS = 250;

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
            ->retry(
                self::MAX_GENERATION_ATTEMPTS,
                fn (int $attempt, Throwable $exception): int => $this->retryDelay($attempt, $exception),
                fn (Throwable $exception): bool => $this->shouldRetryGeneration($exception),
            )
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

    /**
     * @return array<string, mixed>
     */
    public function getPricing(): array
    {
        return $this->request(false)
            ->get('pricing')
            ->throw()
            ->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function getUsage(string $startDate, string $endDate): array
    {
        return $this->request()
            ->get('usage', [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ])
            ->throw()
            ->json();
    }

    private function request(bool $authenticate = true): PendingRequest
    {
        $request = Http::baseUrl(rtrim((string) config('services.pixelforge.base_url'), '/'))
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('services.pixelforge.timeout', 30));

        if ($authenticate) {
            $request->withToken((string) config('services.pixelforge.api_key'));
        }

        return $request;
    }

    private function shouldRetryGeneration(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        return $exception instanceof RequestException
            && ($exception->response->status() === 429 || $exception->response->serverError());
    }

    private function retryDelay(int $attempt, Throwable $exception): int
    {
        if ($exception instanceof RequestException) {
            $retryAfter = trim((string) $exception->response->header('Retry-After'));

            if (ctype_digit($retryAfter)) {
                return min((int) $retryAfter, intdiv(self::MAX_RETRY_DELAY_MILLISECONDS, 1000)) * 1000;
            }

            if ($retryAfter !== '') {
                try {
                    $retryAt = new DateTimeImmutable($retryAfter);
                    $delay = max(0, ($retryAt->getTimestamp() - now()->getTimestamp()) * 1000);

                    return min($delay, self::MAX_RETRY_DELAY_MILLISECONDS);
                } catch (Throwable) {
                    // Use the bounded fallback delay when the provider's header is invalid.
                }
            }
        }

        return min(
            self::BASE_RETRY_DELAY_MILLISECONDS * (2 ** ($attempt - 1)),
            self::MAX_RETRY_DELAY_MILLISECONDS,
        );
    }
}
