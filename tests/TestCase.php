<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        config(['services.pixelforge.key' => 'test-key', 'services.pixelforge.webhook_secret' => 'whsec_test']);
    }

    protected function pricing(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/pricing.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    protected function object(string $status = 'succeeded', array $overrides = []): array
    {
        return array_replace([
            'id' => 'gen_test', 'object' => 'generation', 'status' => $status,
            'metadata' => [], 'rates' => $this->pricing()['rates'],
            'usage' => $status === 'succeeded' ? [
                'input_text_tokens' => 12, 'input_image_tokens' => 765,
                'output_tokens' => 6450, 'reasoning_tokens' => 3184, 'total_tokens' => 10411,
            ] : null,
            'image_url' => $status === 'succeeded' ? 'https://pixelforge.promptedit.dev/images/test.png' : null,
            'image_url_expires_at' => $status === 'succeeded' ? '2026-11-18T12:00:00Z' : null,
            'completed_at' => in_array($status, ['failed', 'succeeded']) ? '2026-11-17T12:00:00Z' : null,
            'error' => $status === 'failed' ? ['code' => 'generation_failed', 'message' => 'No image was produced.'] : null,
        ], $overrides);
    }
}
