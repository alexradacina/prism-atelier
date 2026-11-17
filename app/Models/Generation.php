<?php

namespace App\Models;

use App\Services\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Generation extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'estimated_nanos' => 'integer', 'reserved_nanos' => 'integer',
            'provider_nanos' => 'integer', 'actual_nanos' => 'integer',
            'provider_error' => 'array', 'reference_images' => 'array', 'quoted_pricing' => 'array', 'rates' => 'array', 'usage' => 'array',
            'completed_at' => 'immutable_datetime', 'synced_at' => 'immutable_datetime',
            'image_url_expires_at' => 'immutable_datetime', 'settled_at' => 'immutable_datetime',
            'provider_slot' => 'boolean', 'submission_uncertain' => 'boolean',
            'provider_created_at' => 'immutable_datetime', 'submission_retry_at' => 'immutable_datetime',
            'sync_retry_at' => 'immutable_datetime', 'submission_attempts' => 'integer', 'sync_failures' => 'integer',
            'sync_paused_at' => 'immutable_datetime', 'sync_error_status' => 'integer',
            'submission_pricing' => 'array', 'pricing_checked_at' => 'immutable_datetime', 'pricing_failures' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function terminal(): bool
    {
        return in_array($this->status, ['succeeded', 'failed'], true);
    }

    public function display(): array
    {
        $notice = $this->waiting_message;
        if ($notice === null && ! $this->terminal() && $this->pricing_checked_at && isset($this->quoted_pricing['tokens'])) {
            $quotedMaximum = Money::estimate($this->only(['prompt', 'size', 'quality', 'reference_images']), $this->quoted_pricing)['maximum_nanos'];
            if ($this->reserved_nanos > $quotedMaximum) {
                $notice = 'Your credit hold was increased after a pricing refresh. Unused credits return when this image finishes.';
            }
        }

        return [
            'id' => $this->id, 'prompt' => $this->prompt, 'size' => $this->size, 'quality' => $this->quality,
            'status' => $this->status, 'image_url' => $this->image_url,
            'image_url_expires_at' => $this->image_url_expires_at?->toIso8601String(),
            'estimated_cost' => Money::format($this->estimated_nanos),
            'actual_cost' => $this->actual_nanos === null ? null : Money::format($this->actual_nanos),
            'error' => $this->error_message, 'created_at' => $this->created_at->toIso8601String(),
            'notice' => $notice,
            'validation_errors' => $this->provider_error['errors'] ?? [],
        ];
    }
}
