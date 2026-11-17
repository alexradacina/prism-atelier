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
            'reference_images' => 'array', 'quoted_pricing' => 'array', 'rates' => 'array', 'usage' => 'array',
            'completed_at' => 'immutable_datetime', 'synced_at' => 'immutable_datetime',
            'image_url_expires_at' => 'immutable_datetime', 'settled_at' => 'immutable_datetime',
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
        return [
            'id' => $this->id, 'prompt' => $this->prompt, 'size' => $this->size, 'quality' => $this->quality,
            'status' => $this->status, 'image_url' => $this->image_url,
            'image_url_expires_at' => $this->image_url_expires_at?->toIso8601String(),
            'estimated_cost' => Money::format($this->estimated_nanos),
            'actual_cost' => $this->actual_nanos === null ? null : Money::format($this->actual_nanos),
            'error' => $this->error_message, 'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
