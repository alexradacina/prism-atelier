<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class LedgerEntry extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_nanos' => 'integer', 'balance_after_nanos' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Balance history entries cannot be edited.'));
        static::deleting(fn () => throw new LogicException('Balance history entries cannot be deleted.'));
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
