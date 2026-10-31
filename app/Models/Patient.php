<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Patient extends Model
{
    use SoftDeletes;
    protected $table = 'patients';

    protected $fillable = [
    'clinic_id',
    'phone',
    'record_clinical',
    'first_name',
    'last_name',
    'birth_date',
    'address',
    'emergency_contact_name',
    'emergency_contact_phone',
    'notes',
    'status',
    'gender',
];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
        ];
    }

    /**
     * Clínica a la que pertenece el paciente.
     */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class, 'clinic_id');
    }

    /**
     * Citas del paciente.
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'patient_id');
    }
}