<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'clinic_id',
        'appointment_id',
        'patient_id',
        'received_by',
        'amount',
        'payment_method',
        'status',
        'paid_at',
        'reference',
        'notes',
        'idempotency_key',
        'payment_group_id',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}



// use Illuminate\Database\Eloquent\Model;

// use Illuminate\Database\Eloquent\Relations\BelongsTo;
// use Illuminate\Database\Eloquent\Relations\HasMany;

// class Payment extends Model
// {
//     protected $fillable = [
//         'appointment_id',
//         'clinic_id',
//         'patient_id',
//         'amount',
//         'payment_method',
//         'status',
//         'paid_at',
//         'received_by',
//         'reference',
//         'notes',
//         'cancelled_at',
//         'cancelled_by',
//         'cancellation_reason',
//     ];

//     protected function casts(): array
//     {
//         return [
//             'amount' => 'decimal:2',
//             'paid_at' => 'datetime',
//             'cancelled_at' => 'datetime',
//         ];
//     }

//     public function clinic(): BelongsTo
//     {
//         return $this->belongsTo(Clinic::class);
//     }

//     public function patient(): BelongsTo
//     {
//         return $this->belongsTo(Patient::class);
//     }

//     public function appointment(): BelongsTo
//     {
//         return $this->belongsTo(Appointment::class);
//     }

//     public function receivedBy(): BelongsTo
//     {
//         return $this->belongsTo(User::class, 'received_by');
//     }

//     public function cancelledBy(): BelongsTo
//     {
//         return $this->belongsTo(User::class, 'cancelled_by');
//     }

//     public function allocations(): HasMany
//     {
//         return $this->hasMany(PaymentAllocation::class);
//     }
// }
