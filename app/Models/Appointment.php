<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    protected $table = 'appointments';

    protected $fillable = [
        'clinic_id',
        'patient_id',
        'dentist_user_id',
        'treatment_id',
        'treatment_price',
        'title',
        'start_at',
        'end_at',
        'status',
        'notes',
    ];

    /**
     * Conversión de atributos.
     */
    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'treatment_price' => 'decimal:2',
        ];
    }

    /**
     * Clínica a la que pertenece la cita.
     */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class, 'clinic_id');
    }

    /**
     * Paciente de la cita.
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    /**
     * Dentista asignado a la cita.
     */
    public function dentist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dentist_user_id');
    }

    /** Tramiento  */
    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class, 'treatment_id');
    }
}