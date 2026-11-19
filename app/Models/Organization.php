<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    protected $table = 'organizations';

    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Usuarios pertenecientes a la organización.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'organization_user',
            'organization_id',
            'user_id'
        )->withPivot('role')
         ->withTimestamps();
    }

    /**
     * Clínicas pertenecientes a la organización.
     */
    public function clinics(): HasMany
    {
        return $this->hasMany(Clinic::class, 'organization_id');
    }
}