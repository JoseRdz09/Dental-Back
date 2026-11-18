<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\Treatment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Carbon;

class AppointmentController extends Controller
{
    /**
     * Obtener clínicas, citas y opciones del formulario.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'clinic_id' => ['sometimes', 'integer', 'exists:clinics,id'],
            'start' => ['sometimes', 'date'],
            'end' => ['sometimes', 'date', 'after:start'],
        ]);

        $userId = $request->user()->id;

        $clinics = Clinic::query()
            ->whereHas('users', function ($query) use ($userId) {
                $query->where('users.id', $userId);
            })
            ->get();

        if ($clinics->isEmpty()) {
            return response()->json([
                'clinics' => [],
                'selected_clinic_id' => null,
                'data' => [],
                'options' => [
                    'patients' => [],
                    'dentists' => [],
                    'treatments' => [],
                ],
            ]);
        }

        $clinicId = $validated['clinic_id'] ?? $clinics->first()->id;

        $clinic = $clinics->firstWhere('id', $clinicId);

        abort_unless(
            $clinic,
            403,
            'No tienes acceso a esta clínica.'
        );

        // Pacientes activos.
        $patients = Patient::query()
            ->where('clinic_id', $clinicId)
            ->where('status', 'active')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get([
                'id',
                'first_name',
                'last_name',
            ]);

        // Dentistas de la clínica.
        $dentists = $clinic->users()
            ->wherePivot('role', 'dentist')
            ->orderBy('users.name')
            ->get([
                'users.id',
                'users.name',
            ]);

        // Tratamientos activos.
        $treatments = Treatment::query()
            ->where('clinic_id', $clinicId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'description',
                'duration',
                'price',
            ]);

        // Citas.
        $query = Appointment::query()
            ->where('clinic_id', $clinicId)
            ->with([
                'patient:id,first_name,last_name',
                'dentist:id,name',
                'treatment:id,name,duration,price',
            ])
            ->orderBy('start_at');

        // Los filtros se comparan en UTC.
        if (isset($validated['start'])) {
            $query->where(
                'end_at',
                '>',
                $this->toUtc($validated['start'])
            );
        }

        if (isset($validated['end'])) {
            $query->where(
                'start_at',
                '<',
                $this->toUtc($validated['end'])
            );
        }

        return response()->json([
            'clinics' => $clinics,
            'selected_clinic_id' => $clinic->id,
            'data' => $query->get(),
            'options' => [
                'patients' => $patients,
                'dentists' => $dentists,
                'treatments' => $treatments,
            ],
        ]);
    }

    /**
     * Crear una cita.
     */
    public function store(Request $request): JsonResponse
    {
        // Identificar la clínica.
        $clinicData = $request->validate([
            'clinic_id' => [
                'required',
                'integer',
                'exists:clinics,id',
            ],
        ]);

        // Comprobar acceso a la clínica.
        $clinic = $this->getAccessibleClinic(
            $request,
            (int) $clinicData['clinic_id']
        );

        abort_unless(
            $clinic,
            403,
            'No tienes acceso a esta clínica.'
        );

        // Validar todos los datos y el horario.
        $validated = $this->validateAppointment(
            $request,
            $clinic
        );

        $treatment = Treatment::query()
            ->where('clinic_id', $clinic->id)
            ->where('is_active', true)
            ->findOrFail($validated['treatment_id']);

        $appointment = DB::transaction(function () use (
            $validated,
            $clinic,
            $treatment
        ) {
            return Appointment::create([
                'clinic_id' => $clinic->id,
                'patient_id' => $validated['patient_id'],
                'dentist_user_id' => $validated['dentist_user_id'] ?? null,
                'treatment_id' => $treatment->id,
                'treatment_price' => $treatment->price,
                'title' => $treatment->name,

                // Almacenar siempre en UTC.
                'start_at' => $this->toUtc($validated['start_at']),
                'end_at' => $this->toUtc($validated['end_at']),

                'status' => $validated['status'],
                'notes' => $validated['notes'] ?? null,
            ]);
        });

        return response()->json([
            'message' => 'Cita creada correctamente.',
            'data' => $appointment->fresh()->load([
                'patient:id,first_name,last_name',
                'dentist:id,name',
                'treatment:id,name,duration,price',
            ]),
        ], 201);
    }

    /**
     * Actualizar una cita.
     */
    public function update(
        Request $request,
        Appointment $appointment
    ): JsonResponse {
        // Comprobar acceso a la clínica.
        $clinic = $this->getAccessibleClinic(
            $request,
            (int) $appointment->clinic_id
        );

        abort_unless(
            $clinic,
            403,
            'No tienes acceso a esta clínica.'
        );

        // Validar permitiendo conservar el horario original.
        $validated = $this->validateAppointment(
            $request,
            $clinic,
            $appointment
        );

        $treatment = Treatment::query()
            ->where('clinic_id', $clinic->id)
            ->where('is_active', true)
            ->findOrFail($validated['treatment_id']);

        DB::transaction(function () use (
            $appointment,
            $validated,
            $treatment
        ) {
            $appointment->update([
                'patient_id' => $validated['patient_id'],
                'dentist_user_id' => $validated['dentist_user_id'] ?? null,
                'treatment_id' => $treatment->id,
                'treatment_price' => $treatment->price,
                'title' => $treatment->name,

                // Almacenar siempre en UTC.
                'start_at' => $this->toUtc($validated['start_at']),
                'end_at' => $this->toUtc($validated['end_at']),

                'status' => $validated['status'],
                'notes' => $validated['notes'] ?? null,
            ]);
        });

        return response()->json([
            'message' => 'Cita actualizada correctamente.',
            'data' => $appointment->fresh()->load([
                'patient:id,first_name,last_name',
                'dentist:id,name',
                'treatment:id,name,duration,price',
            ]),
        ]);
    }

    /**
     * Convertir una fecha ISO 8601 a UTC.
     *
     * Se conserva el instante representado por la fecha recibida.
     */
    private function toUtc(string $value): Carbon
    {
        return Carbon::parse($value)->utc();
    }

    /**
     * Validar los datos de una cita.
     *
     * Reglas:
     *
     * CREACIÓN:
     * - No permite fechas pasadas.
     * - El inicio debe ser estrictamente posterior a ahora.
     * - Inicio y fin deben utilizar intervalos de 5 minutos.
     * - La finalización debe ser posterior al inicio.
     *
     * EDICIÓN:
     * - Permite conservar el inicio original aunque ya haya pasado.
     * - Permite conservar el fin original aunque no coincida con múltiplos de 5.
     * - Si cambia el inicio, debe ser futuro.
     * - Si cambia el inicio o el fin, debe respetar intervalos de 5 minutos.
     */
    private function validateAppointment(
        Request $request,
        Clinic $clinic,
        ?Appointment $appointment = null
    ): array {
        $validated = $request->validate([
            'clinic_id' => [
                'sometimes',
                'integer',
                Rule::in([$clinic->id]),
            ],

            'patient_id' => [
                'required',
                'integer',
                Rule::exists('patients', 'id')->where(
                    fn ($query) => $query
                        ->where('clinic_id', $clinic->id)
                        ->where('status', 'active')
                        ->whereNull('deleted_at')
                ),
            ],

            // Dentista opcional.
            'dentist_user_id' => [
                'nullable',
                'integer',
                Rule::exists('clinic_user', 'user_id')->where(
                    fn ($query) => $query
                        ->where('clinic_id', $clinic->id)
                        ->where('role', 'dentist')
                ),
            ],

            'treatment_id' => [
                'required',
                'integer',
                Rule::exists('treatments', 'id')->where(
                    fn ($query) => $query
                        ->where('clinic_id', $clinic->id)
                        ->where('is_active', true)
                ),
            ],

            'start_at' => [
                'required',
                'date',
            ],

            'end_at' => [
                'required',
                'date',
                'after:start_at',
            ],

            'status' => [
                'required',
                'string',
                Rule::in([
                    'Pendiente',
                    'Confirmada',
                    'Completada',
                    'Cancelada',
                ]),
            ],

            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        /*
         * ZONA HORARIA DE LA CLÍNICA
         */

        $timezone = $clinic->timezone ?: config('app.timezone', 'UTC');

        // Interpretar los instantes recibidos y mostrarlos
        // en la zona horaria de la clínica.
        $startAt = $this->toUtc($validated['start_at'])
            ->setTimezone($timezone);

        $endAt = $this->toUtc($validated['end_at'])
            ->setTimezone($timezone);

        $now = Carbon::now($timezone);

        /*
         * COMPARAR CON EL HORARIO ORIGINAL
         */

        $sameOriginalStart = false;
        $sameOriginalEnd = false;

        if ($appointment) {
            $originalStart = Carbon::parse($appointment->start_at)
                ->utc();

            $originalEnd = Carbon::parse($appointment->end_at)
                ->utc();

            $newStart = $this->toUtc($validated['start_at']);
            $newEnd = $this->toUtc($validated['end_at']);

            // Comparar instantes, no cadenas de fechas.
            $sameOriginalStart = $originalStart->equalTo($newStart);
            $sameOriginalEnd = $originalEnd->equalTo($newEnd);
        }

        /*
         * VALIDAR INTERVALOS DE 5 MINUTOS
         *
         * Se comprueban los minutos en la zona local de la clínica.
         * Los minutos son iguales en UTC y en la zona local.
         */

        if (
            !$sameOriginalStart &&
            $startAt->minute % 5 !== 0
        ) {
            throw ValidationException::withMessages([
                'start_at' => [
                    'La hora de inicio debe utilizar intervalos de 5 minutos.',
                ],
            ]);
        }

        if (
            !$sameOriginalEnd &&
            $endAt->minute % 5 !== 0
        ) {
            throw ValidationException::withMessages([
                'end_at' => [
                    'La hora de finalización debe utilizar intervalos de 5 minutos.',
                ],
            ]);
        }

        /*
         * VALIDAR FECHAS Y HORAS PASADAS
         */

        // Si el inicio es idéntico al original, permitimos conservarlo.
        // Esto permite cambiar el estado sin reprogramar la cita.
        //
        // Si se modifica el inicio, debe ser estrictamente futuro.
        if (
            !$sameOriginalStart &&
            $startAt->lessThanOrEqualTo($now)
        ) {
            throw ValidationException::withMessages([
                'start_at' => [
                    'La hora de inicio debe ser posterior a la hora actual de la clínica.',
                ],
            ]);
        }

        /*
         * VALIDACIÓN FINAL
         *
         * Se vuelve a comprobar la relación entre ambos instantes.
         */
        if (!$endAt->greaterThan($startAt)) {
            throw ValidationException::withMessages([
                'end_at' => [
                    'La hora de finalización debe ser posterior a la hora de inicio.',
                ],
            ]);
        }

        return $validated;
    }

    /**
     * Comprobar acceso del usuario a la clínica.
     */
    private function getAccessibleClinic(
        Request $request,
        int $clinicId
    ): ?Clinic {
        return Clinic::query()
            ->whereKey($clinicId)
            ->whereHas('users', function ($query) use ($request) {
                $query->where('users.id', $request->user()->id);
            })
            ->first();
    }
}