<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\GenerationService;
use App\Services\Pixelforge;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class SyncGeneration implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 25;

    public function __construct(public string $generationId) {}

    public function backoff(): array
    {
        return [15, 30, 60, 120];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('sync:'.$this->generationId))->dontRelease()->expireAfter(60)];
    }

    public function handle(Pixelforge $provider, GenerationService $service): void
    {
        $generation = Generation::findOrFail($this->generationId);
        if ($generation->terminal() || ! $generation->provider_id) {
            return;
        }
        try {
            $object = $provider->retrieve($generation->provider_id);
        } catch (RequestException $exception) {
            if (in_array($exception->response->status(), [429, 503], true) && $this->attempts() < $this->tries) {
                $delay = max((int) $exception->response->header('Retry-After', 15), $this->backoff()[max(0, min($this->attempts() - 1, 3))]);
                $this->release($delay);

                return;
            }
            throw $exception;
        }
        $service->apply($generation, $object);
        if (! $generation->fresh()->terminal()) {
            self::dispatch($generation->id)->delay(now()->addSeconds(15));
        }
    }
}
