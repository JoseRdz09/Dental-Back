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
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Carbon;

class AppointmentController extends Controller
{
    /**
     * Obtener únicamente las clínicas autorizadas.
     */
    public function clinics(Request $request): JsonResponse
    {
        $clinics = Clinic::query()
            ->whereHas('users', function ($query) use ($request) {
                $query->where('users.id', $request->user()->id);
            })
            ->orderBy('name')
            ->get([
                'id',
                'name',
            ]);

        return response()->json([
            'data' => $clinics,
        ]);
    }

    /**
     * Obtener citas de una clínica dentro del rango solicitado.
     *
     * No descarga clínicas, pacientes, dentistas ni tratamientos.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'clinic_id' => [
                'required',
                'integer',
                'exists:clinics,id',
            ],
            'start' => [
                'required',
                'date',
            ],
            'end' => [
                'required',
                'date',
                'after:start',
            ],
        ]);

        $clinic = $this->getAccessibleClinic(
            $request,
            (int) $validated['clinic_id']
        );

        abort_unless(
            $clinic,
            403,
            'No tienes acceso a esta clínica.'
        );

        $start = $this->toUtc($validated['start']);
        $end = $this->toUtc($validated['end']);

        $appointments = Appointment::query()
            ->where('clinic_id', $clinic->id)

            // Solo citas que coinciden con el rango solicitado.
            ->where('end_at', '>', $start)
            ->where('start_at', '<', $end)

            // Seleccionar únicamente las columnas necesarias.
            ->select([
                'id',
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
            ])

            // Cargar únicamente los campos utilizados de las relaciones.
            ->with([
                'patient:id,first_name,last_name',
                'dentist:id,name',
                'treatment:id,name,duration,price,is_active',
            ])
            ->orderBy('start_at')
            ->get();

        return response()->json([
            'data' => $appointments,
        ]);
    }

    /**
     * Obtener estadísticas del mes visualizado.
     *
     * - Citas hoy: citas del día actual, si se visualiza el mes actual.
     * - Por confirmar: citas pendientes del mes.
     * - Próximas confirmadas: citas confirmadas futuras del mes.
     * - Canceladas: citas canceladas del mes.
     */
    public function stats(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'clinic_id' => [
                'required',
                'integer',
                'exists:clinics,id',
            ],
            'start' => [
                'required',
                'date',
            ],
            'end' => [
                'required',
                'date',
                'after:start',
            ],
        ]);

        $clinic = $this->getAccessibleClinic(
            $request,
            (int) $validated['clinic_id']
        );

        abort_unless(
            $clinic,
            403,
            'No tienes acceso a esta clínica.'
        );

        $timezone = $clinic->timezone ?: config('app.timezone', 'UTC');

        $start = $this->toUtc($validated['start']);
        $end = $this->toUtc($validated['end']);

        $now = Carbon::now($timezone);

        // Determinar si el mes visualizado es el mes actual.
        $viewedMonth = $start->copy()
            ->setTimezone($timezone)
            ->format('Y-m');

        $currentMonth = $now->format('Y-m');

        $todayCount = 0;

        if ($viewedMonth === $currentMonth) {
            $todayStart = $now->copy()
                ->startOfDay()
                ->utc();

            $todayEnd = $now->copy()
                ->addDay()
                ->startOfDay()
                ->utc();

            $todayCount = Appointment::query()
                ->where('clinic_id', $clinic->id)
                ->where('start_at', '>=', $todayStart)
                ->where('start_at', '<', $todayEnd)
                ->where('status', '!=', 'Cancelada')
                ->count();
        }

        // Citas pendientes del mes.
        $pendingCount = Appointment::query()
            ->where('clinic_id', $clinic->id)
            ->where('start_at', '>=', $start)
            ->where('start_at', '<', $end)
            ->where('status', 'Pendiente')
            ->count();

        // Próximas citas confirmadas del mes.
        $upcomingCount = Appointment::query()
            ->where('clinic_id', $clinic->id)
            ->where('start_at', '>=', $start)
            ->where('start_at', '<', $end)
            ->where('start_at', '>=', $now->copy()->utc())
            ->where('status', 'Confirmada')
            ->count();

        // Citas canceladas del mes.
        $cancelledCount = Appointment::query()
            ->where('clinic_id', $clinic->id)
            ->where('start_at', '>=', $start)
            ->where('start_at', '<', $end)
            ->where('status', 'Cancelada')
            ->count();

        return response()->json([
            'data' => [
                'today' => $todayCount,
                'pending' => $pendingCount,
                'upcoming' => $upcomingCount,
                'cancelled' => $cancelledCount,
            ],
        ]);
    }

    /**
     * Obtener dentistas y tratamientos de una clínica.
     *
     * Los pacientes no se descargan aquí.
     */
    public function options(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'clinic_id' => [
                'required',
                'integer',
                'exists:clinics,id',
            ],
        ]);

        $clinic = $this->getAccessibleClinic(
            $request,
            (int) $validated['clinic_id']
        );

        abort_unless(
            $clinic,
            403,
            'No tienes acceso a esta clínica.'
        );

        // Solo ID y nombre de dentistas.
        $dentists = $clinic->users()
            ->wherePivot('role', 'dentist')
            ->orderBy('users.name')
            ->get([
                'users.id',
                'users.name',
            ]);

        /*
         * Tratamientos:
         * - Todos los activos.
         * - Inactivos que estén asociados a citas existentes.
         *
         * Se conserva la posibilidad de editar citas históricas.
         */
        $treatments = Treatment::query()
            ->where('clinic_id', $clinic->id)
            ->where(function ($query) use ($clinic) {
                $query->where('is_active', true)
                    ->orWhereIn(
                        'id',
                        Appointment::query()
                            ->select('treatment_id')
                            ->where('clinic_id', $clinic->id)
                            ->whereNotNull('treatment_id')
                    );
            })
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'description',
                'duration',
                'price',
                'is_active',
            ]);

        return response()->json([
            'data' => [
                'dentists' => $dentists,
                'treatments' => $treatments,
            ],
        ]);
    }

    /**
     * Buscar pacientes activos de una clínica.
     *
     * Devuelve como máximo 10 coincidencias.
     */
    public function searchPatients(
        Request $request,
        int $clinicId
    ): JsonResponse {
        $validated = $request->validate([
            'q' => [
                'required',
                'string',
                'min:1',
                'max:100',
            ],
        ]);

        $clinic = $this->getAccessibleClinic(
            $request,
            $clinicId
        );

        abort_unless(
            $clinic,
            403,
            'No tienes acceso a esta clínica.'
        );

        $search = trim($validated['q']);
        $prefix = $search . '%';

        $patients = Patient::query()
            ->where('clinic_id', $clinic->id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->where(function ($query) use ($prefix) {
                $query->where('first_name', 'ILIKE', $prefix)
                    ->orWhere('last_name', 'ILIKE', $prefix)
                    ->orWhereRaw(
                        "CONCAT(first_name, ' ', last_name) ILIKE ?",
                        [$prefix]
                    );
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->limit(10)
            ->get([
                'id',
                'first_name',
                'last_name',
            ]);

        return response()->json([
            'data' => $patients,
        ]);
    }

    /**
     * Crear una cita.
     */
    public function store(Request $request): JsonResponse
    {
        $clinicData = $request->validate([
            'clinic_id' => [
                'required',
                'integer',
                'exists:clinics,id',
            ],
        ]);

        $clinic = $this->getAccessibleClinic(
            $request,
            (int) $clinicData['clinic_id']
        );

        abort_unless(
            $clinic,
            403,
            'No tienes acceso a esta clínica.'
        );

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
                'treatment:id,name,duration,price,is_active',
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
        $clinic = $this->getAccessibleClinic(
            $request,
            (int) $appointment->clinic_id
        );

        abort_unless(
            $clinic,
            403,
            'No tienes acceso a esta clínica.'
        );

        $validated = $this->validateAppointment(
            $request,
            $clinic,
            $appointment
        );

        $treatment = Treatment::query()
            ->where('clinic_id', $clinic->id)
            ->where(function ($query) use ($appointment) {
                $query->where('is_active', true)
                    ->orWhere('id', $appointment->treatment_id);
            })
            ->findOrFail($validated['treatment_id']);

        DB::transaction(function () use (
            $appointment,
            $validated,
            $treatment
        ) {
            $isHistoricalInactiveTreatment =
                !$treatment->is_active &&
                (int) $appointment->treatment_id === (int) $treatment->id;

            $appointment->update([
                'patient_id' => $validated['patient_id'],
                'dentist_user_id' => $validated['dentist_user_id'] ?? null,
                'treatment_id' => $treatment->id,

                'treatment_price' => $isHistoricalInactiveTreatment
                    ? $appointment->treatment_price
                    : $treatment->price,

                'title' => $isHistoricalInactiveTreatment
                    ? $appointment->title
                    : $treatment->name,

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
                'treatment:id,name,duration,price,is_active',
            ]),
        ]);
    }

    /**
     * Convertir una fecha ISO 8601 a UTC.
     */
    private function toUtc(string $value): Carbon
    {
        return Carbon::parse($value)->utc();
    }

    /**
     * Validar los datos de una cita.
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
                    function ($query) use ($clinic, $appointment) {
                        $query->where('clinic_id', $clinic->id)
                            ->where(function ($treatmentQuery) use ($appointment) {
                                $treatmentQuery->where('is_active', true);

                                if ($appointment) {
                                    $treatmentQuery->orWhere(
                                        'id',
                                        $appointment->treatment_id
                                    );
                                }
                            });
                    }
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

        $timezone = $clinic->timezone ?: config('app.timezone', 'UTC');

        $startAt = $this->toUtc($validated['start_at'])
            ->setTimezone($timezone);

        $endAt = $this->toUtc($validated['end_at'])
            ->setTimezone($timezone);

        $now = Carbon::now($timezone);

        $sameOriginalStart = false;
        $sameOriginalEnd = false;

        if ($appointment) {
            $originalStart = Carbon::parse($appointment->start_at)->utc();
            $originalEnd = Carbon::parse($appointment->end_at)->utc();

            $newStart = $this->toUtc($validated['start_at']);
            $newEnd = $this->toUtc($validated['end_at']);

            $sameOriginalStart = $originalStart->equalTo($newStart);
            $sameOriginalEnd = $originalEnd->equalTo($newEnd);
        }

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