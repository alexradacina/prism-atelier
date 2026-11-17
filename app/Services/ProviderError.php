<?php

namespace App\Services;

use Illuminate\Http\Client\Response;

class ProviderError
{
    /** @param array{type: ?string, code: ?string, message: ?string, param: ?string, errors: array<string, list<string>>, http_status: ?int} $details */
    private function __construct(public array $details) {}

    public static function fromResponse(Response $response): self
    {
        return self::fromPayload($response->json('error'), $response->status());
    }

    public static function fromPayload(mixed $payload, ?int $status = null): self
    {
        $payload = is_array($payload) ? $payload : [];
        $details = ['http_status' => $status];
        foreach (['type', 'code', 'message', 'param'] as $field) {
            $details[$field] = isset($payload[$field]) && is_string($payload[$field]) ? $payload[$field] : null;
        }
        $details['errors'] = [];
        foreach (is_array($payload['errors'] ?? null) ? $payload['errors'] : [] as $field => $messages) {
            $messages = array_values(array_filter(is_array($messages) ? $messages : [$messages], is_string(...)));
            if ($messages !== []) {
                $details['errors'][(string) $field] = $messages;
            }
        }

        return new self($details);
    }

    public function retryable(): bool
    {
        return match ($this->details['code']) {
            'validation_failed' => false,
            'daily_limit_exceeded', 'concurrency_limit_exceeded', 'rate_limit_exceeded', 'internal_error' => true,
            default => ! ($this->details['http_status'] >= 400 && $this->details['http_status'] < 500
                && ! in_array($this->details['http_status'], [408, 429], true)),
        };
    }

    public function definitiveRejection(): bool
    {
        return match ($this->details['code']) {
            'validation_failed', 'daily_limit_exceeded', 'concurrency_limit_exceeded', 'rate_limit_exceeded' => true,
            'internal_error' => false,
            default => in_array($this->details['http_status'], [400, 401, 403, 404, 409, 422, 429, 503], true),
        };
    }

    public function submissionMessage(): string
    {
        return match ($this->details['code']) {
            'validation_failed' => $this->details['message'] ?? 'Some image settings are invalid. Please review the details and try again.',
            'daily_limit_exceeded' => 'The image service’s daily allowance is full. Please try again after the next UTC day begins. You were not charged.',
            'concurrency_limit_exceeded', 'rate_limit_exceeded' => 'The image service is at capacity. Please try creating this image again in a few minutes. You were not charged.',
            default => match ($this->details['http_status']) {
                429 => 'The image service is at capacity. Please try creating this image again in a few minutes. You were not charged.',
                503 => 'The image service is temporarily busy. Please try creating this image again shortly. You were not charged.',
                default => $this->details['message'] ?? 'The image service rejected this request. You were not charged.',
            },
        };
    }
}
