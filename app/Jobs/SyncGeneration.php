<?php

namespace App\Jobs;

use App\Exceptions\ProviderLimited;
use App\Models\Generation;
use App\Services\GenerationService;
use App\Services\Pixelforge;
use App\Services\ProviderError;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class SyncGeneration implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 5;

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
        if ($generation->terminal() || ! $generation->provider_id || $generation->sync_paused_at !== null) {
            return;
        }
        if ($generation->sync_retry_at?->isFuture()) {
            $this->release(max(1, $generation->sync_retry_at->timestamp - now()->timestamp));

            return;
        }
        try {
            $object = $provider->retrieve($generation->provider_id);
        } catch (ProviderLimited $exception) {
            $generation->update(['sync_retry_at' => now()->addSeconds($exception->retryAfter)->ceilSecond(), 'waiting_message' => $exception->getMessage()]);
            $this->release($exception->retryAfter);

            return;
        } catch (RequestException|ConnectionException $exception) {
            $status = $exception instanceof RequestException ? $exception->response->status() : null;
            $error = $exception instanceof RequestException ? ProviderError::fromResponse($exception->response) : null;
            if ($error !== null) {
                $generation->update(['provider_error' => $error->details]);
            }
            if ($error !== null && ! $error->retryable()) {
                $paused = Generation::whereKey($generation->id)->whereIn('status', ['queued', 'processing'])->whereNull('sync_paused_at')->update([
                    'sync_paused_at' => now(), 'sync_error_status' => $status, 'sync_retry_at' => null, 'waiting_message' => null,
                    'error_message' => 'We cannot confirm this image’s status. Support needs to investigate. Your credits remain reserved and no final charge has been recorded.',
                ]);
                if ($paused) {
                    Log::warning('Pixelforge status polling paused for operator review.', ['generation_id' => $generation->id, 'http_status' => $status]);
                    $this->fail(new \RuntimeException('Status polling paused after HTTP '.$status.'. Resolve the cause and run prism:resume-polling.'));
                }

                return;
            }
            $failures = $generation->sync_failures + 1;
            $retryAfter = $exception instanceof RequestException ? (int) $exception->response->header('Retry-After', 0) : 0;
            $delay = max($retryAfter, $failures >= 5 ? 300 : $this->backoff()[min($failures - 1, 3)]);
            $generation->update(['sync_retry_at' => now()->addSeconds($delay)->ceilSecond(), 'sync_failures' => $failures >= 5 ? 0 : $failures,
                'waiting_message' => 'The image service is busy. We’re still checking your image’s status.']);
            if ($failures < 5) {
                $this->release($delay);
            }

            return;
        }
        $service->apply($generation, $object);
        if (! $generation->fresh()->terminal()) {
            $generation->update(['sync_failures' => 0, 'sync_retry_at' => now()->addSeconds(15)->ceilSecond()]);
            self::dispatch($generation->id)->delay(now()->addSeconds(15));
        }
    }
}
