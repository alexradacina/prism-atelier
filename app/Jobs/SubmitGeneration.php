<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\GenerationService;
use App\Services\Pixelforge;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class SubmitGeneration implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public int $timeout = 25;

    public int $uniqueFor = 900;

    public function __construct(public string $generationId) {}

    public function uniqueId(): string
    {
        return $this->generationId;
    }

    public function backoff(): array
    {
        return [5, 15, 30, 60, 120];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('submit:'.$this->generationId))->releaseAfter(10)->expireAfter(60)];
    }

    public function handle(Pixelforge $provider, GenerationService $service): void
    {
        $generation = Generation::findOrFail($this->generationId);
        if ($generation->terminal() || $generation->provider_id !== null) {
            return;
        }
        try {
            $object = $provider->submit($generation);
        } catch (RequestException $exception) {
            $status = $exception->response->status();
            if (($status >= 400 && $status < 500 && $status !== 429) || (in_array($status, [429, 503], true) && $this->attempts() >= $this->tries)) {
                $service->reject($generation, $exception->response->json('error.message') ?? 'The provider rejected this request. You were not charged.');

                return;
            }
            if (in_array($status, [429, 503], true)) {
                $delay = max((int) $exception->response->header('Retry-After', 5), $this->backoff()[max(0, min($this->attempts() - 1, 4))]);
                $this->release($delay);

                return;
            }
            throw $exception;
        }
        $service->apply($generation, $object);
        if (! $generation->fresh()->terminal()) {
            SyncGeneration::dispatch($generation->id)->delay(now()->addSeconds(10));
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Generation::whereKey($this->generationId)->whereNull('provider_id')->whereIn('status', ['queued', 'processing'])
            ->update(['error_message' => 'Submission could not be confirmed. Credits remain reserved while an operator safely retries the request.']);
    }
}
