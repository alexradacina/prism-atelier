<?php

namespace App\Services;

use App\Models\Generation;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class Pixelforge
{
    public function http(): PendingRequest
    {
        return Http::baseUrl(config('services.pixelforge.url'))
            ->withToken(config('services.pixelforge.key') ?? '')
            ->acceptJson()->asJson()->connectTimeout(5)->timeout(15);
    }

    public function pricing(): array
    {
        return Cache::remember('pixelforge.pricing', 60, fn () => $this->http()->get('pricing')->throw()->json());
    }

    public function submit(Generation $generation): array
    {
        return $this->http()->withHeaders(['Idempotency-Key' => $generation->id])
            ->post('generations', [
                'prompt' => $generation->prompt, 'size' => $generation->size, 'quality' => $generation->quality,
                'reference_images' => $generation->reference_images,
                'metadata' => ['local_generation_id' => $generation->id],
            ])->throw()->json();
    }

    public function retrieve(string $id): array
    {
        return $this->http()->get('generations/'.rawurlencode($id))->throw()->json();
    }

    public function usage(string $start, string $end): array
    {
        return $this->http()->get('usage', ['start_date' => $start, 'end_date' => $end])->throw()->json();
    }
}
