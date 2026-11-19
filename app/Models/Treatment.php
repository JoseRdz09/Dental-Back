<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Treatment extends Model
{
    protected $fillable = [
        'clinic_id',
        'name',
        'description',
        'duration',
        'price',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'duration' => 'integer',
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}