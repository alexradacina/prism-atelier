<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LedgerEntry extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_nanos' => 'integer'];
    }
}
