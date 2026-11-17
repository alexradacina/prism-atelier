<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'immutable_datetime', 'processing_attempts' => 'integer',
            'last_attempted_at' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime'];
    }
}
