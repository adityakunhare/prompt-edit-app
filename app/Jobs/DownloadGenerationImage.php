<?php

namespace App\Jobs;

use App\Models\Generation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DownloadGenerationImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [5, 15];

    public int $timeout = 30;

    private const int MAX_IMAGE_BYTES = 26214400;

    public function __construct(public int $generationId) {}

    public function handle(): void
    {
        $generation = Generation::query()->find($this->generationId);

        if (
            $generation === null
            || $generation->status !== 'succeeded'
            || blank($generation->image_url)
            || $generation->image_path !== null
            || ! $this->isAllowedImageUrl($generation->image_url)
        ) {
            return;
        }

        $response = Http::connectTimeout(5)
            ->timeout((int) config('services.pixelforge.image_download_timeout', 20))
            ->withoutRedirecting()
            ->get($generation->image_url);

        if (! $response->successful()) {
            if ($response->serverError() || $response->status() === 429) {
                $response->throw();
            }

            return;
        }

        $imageType = $this->imageType($response);
        $contents = $response->body();

        if (
            $imageType === null
            || $contents === ''
            || strlen($contents) > self::MAX_IMAGE_BYTES
            || (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) !== $imageType['mime']
        ) {
            return;
        }

        $imagePath = sprintf(
            'generations/%d/%d/image.%s',
            $generation->user_id,
            $generation->getKey(),
            $imageType['extension'],
        );
        $disk = Storage::disk((string) config('services.pixelforge.image_disk', 'local'));

        if (! $disk->put($imagePath, $contents, ['visibility' => 'private'])) {
            throw new RuntimeException('The generated image could not be stored.');
        }

        Generation::query()
            ->whereKey($generation->getKey())
            ->where('status', 'succeeded')
            ->whereNull('image_path')
            ->update([
                'image_path' => $imagePath,
                'updated_at' => now(),
            ]);
    }

    private function isAllowedImageUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (
            ! is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)
        ) {
            return false;
        }

        $allowedHosts = config('services.pixelforge.image_hosts', []);

        if (! is_array($allowedHosts)) {
            return false;
        }

        $allowedHosts = array_map('strtolower', $allowedHosts);

        return in_array(strtolower($parts['host']), $allowedHosts, true);
    }

    /**
     * @return array{mime: string, extension: string}|null
     */
    private function imageType(Response $response): ?array
    {
        $contentType = strtolower(trim(explode(';', (string) $response->header('Content-Type'), 2)[0]));

        return match ($contentType) {
            'image/png' => ['mime' => 'image/png', 'extension' => 'png'],
            'image/jpeg' => ['mime' => 'image/jpeg', 'extension' => 'jpg'],
            'image/webp' => ['mime' => 'image/webp', 'extension' => 'webp'],
            default => null,
        };
    }
}
