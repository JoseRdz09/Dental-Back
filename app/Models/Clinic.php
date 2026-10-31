<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Clinic extends Model
{
    use SoftDeletes;
    
    protected $table = 'clinics';

    protected $fillable = [
        'organization_id',
        'name',
        'email',
        'phone',
        'address',
        'status',
        'timezone',
    ];

    /**
     * Organización a la que pertenece la clínica.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    /**
     * Usuarios pertenecientes a la clínica.
     * La tabla intermedia clinic_user contiene el rol.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'clinic_user', 'clinic_id', 'user_id')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Citas registradas en la clínica.
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'clinic_id');
    }

    /**
     * Pacientes registrados en la clínica.
     */
    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class, 'clinic_id');
    }
}