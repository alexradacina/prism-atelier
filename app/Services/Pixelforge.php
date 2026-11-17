<?php

namespace App\Services;

use App\Models\Generation;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class Pixelforge
{
    public function __construct(private ProviderLimits $limits) {}

    private function http(): PendingRequest
    {
        return Http::baseUrl(config('services.pixelforge.url'))
            ->withToken(config('services.pixelforge.key') ?? '')
            ->acceptJson()->asJson()->connectTimeout(5)->timeout(15);
    }

    public function pricing(bool $fresh = false): array
    {
        $fetch = fn () => Http::baseUrl(config('services.pixelforge.url'))
            ->acceptJson()->connectTimeout(5)->timeout(15)->get('pricing')->throw()->json();
        if ($fresh) {
            $pricing = $fetch();
            Cache::put('pixelforge.pricing', $pricing, 60);

            return $pricing;
        }

        return Cache::remember('pixelforge.pricing', 60, $fetch);
    }

    public function checkSubmission(Generation $generation): void
    {
        $this->limits->acquire($generation, reserve: false);
    }

    public function submit(Generation $generation): array
    {
        $wasUncertain = $this->limits->acquire($generation);
        $response = $this->http()->withHeaders(['Idempotency-Key' => $generation->id])
            ->post('generations', [
                'prompt' => $generation->prompt, 'size' => $generation->size, 'quality' => $generation->quality,
                'reference_images' => $generation->reference_images,
                'metadata' => ['local_generation_id' => $generation->id],
            ]);
        $this->limits->observe($response);
        if (in_array($response->status(), [400, 401, 404, 409, 422, 429, 503], true)) {
            $this->limits->rejected($generation, $wasUncertain);
        }

        return $response->throw()->json();
    }

    public function retrieve(string $id): array
    {
        return $this->request(fn () => $this->http()->get('generations/'.rawurlencode($id)));
    }

    public function usage(string $start, string $end): array
    {
        return $this->request(fn () => $this->http()->get('usage', ['start_date' => $start, 'end_date' => $end]));
    }

    private function request(callable $send): array
    {
        $this->limits->acquire();
        /** @var Response $response */
        $response = $send();
        $this->limits->observe($response);

        return $response->throw()->json();
    }
}
