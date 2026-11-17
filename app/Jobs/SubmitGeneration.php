<?php

namespace App\Jobs;

use App\Exceptions\ProviderLimited;
use App\Models\Generation;
use App\Services\GenerationService;
use App\Services\Pixelforge;
use App\Services\ProviderLimits;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class SubmitGeneration implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 6;

    public int $timeout = 25;

    public int $uniqueFor = 172800;

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
        if ($generation->submission_attempts >= ProviderLimits::MAX_SUBMISSION_ATTEMPTS) {
            $this->finishRetries($generation, $service, 'The image service could not accept this request. You were not charged.');

            return;
        }
        try {
            $object = $provider->submit($generation);
        } catch (ProviderLimited $exception) {
            $generation->update(['waiting_message' => $exception->getMessage(), 'submission_retry_at' => now()->addSeconds($exception->retryAfter)->ceilSecond()]);
            $this->release($exception->retryAfter);

            return;
        } catch (RequestException $exception) {
            $generation->refresh();
            $status = $exception->response->status();
            if (in_array($status, [400, 401, 404, 409, 422], true) || $generation->submission_attempts >= ProviderLimits::MAX_SUBMISSION_ATTEMPTS) {
                $this->finishRetries($generation, $service, $exception->response->json('error.message') ?? 'The image service rejected this request. You were not charged.');

                return;
            }
            $this->retryLater($generation, (int) $exception->response->header('Retry-After', 0));

            return;
        } catch (ConnectionException $exception) {
            $generation->refresh();
            if ($generation->submission_attempts >= ProviderLimits::MAX_SUBMISSION_ATTEMPTS) {
                $this->finishRetries($generation, $service, 'The image service could not accept this request. You were not charged.');
            } else {
                $this->retryLater($generation);
            }

            return;
        }
        $service->apply($generation, $object);
        if (! $generation->fresh()->terminal()) {
            SyncGeneration::dispatch($generation->id)->delay(now()->addSeconds(10));
        }
    }

    private function retryLater(Generation $generation, int $retryAfter = 0): void
    {
        $delay = max($retryAfter, $this->backoff()[max(0, min($generation->submission_attempts - 1, 4))]);
        $generation->update(['submission_retry_at' => now()->addSeconds($delay)->ceilSecond(), 'waiting_message' => 'The image service is busy. Your request will retry automatically.']);
        $this->release($delay);
    }

    private function finishRetries(Generation $generation, GenerationService $service, string $message): void
    {
        if ($generation->fresh()->submission_uncertain) {
            $this->failed(null);
            $this->fail(new \RuntimeException('Submission remains unconfirmed after bounded retries; operator reconciliation is required.'));
        } else {
            $service->reject($generation, $message);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Generation::whereKey($this->generationId)->whereNull('provider_id')->whereIn('status', ['queued', 'processing'])
            ->update(['error_message' => 'Submission could not be confirmed. Credits remain reserved while an operator safely retries the request.', 'waiting_message' => null]);
    }
}
