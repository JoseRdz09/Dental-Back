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

        if (isset($validated['start'])) {
            $query->where('end_at', '>', $validated['start']);
        }

        if (isset($validated['end'])) {
            $query->where('start_at', '<', $validated['end']);
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
        $validated = $this->validateAppointment($request);

        $clinic = $this->getAccessibleClinic(
            $request,
            $validated['clinic_id']
        );

        abort_unless(
            $clinic,
            403,
            'No tienes acceso a esta clínica.'
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
                'dentist_user_id' => $validated['dentist_user_id'],
                'treatment_id' => $treatment->id,
                'treatment_price' => $treatment->price,
                'title' => $treatment->name,
                'start_at' => $validated['start_at'],
                'end_at' => $validated['end_at'],
                'status' => $validated['status'],
                'notes' => $validated['notes'] ?? null,
            ]);
        });

        return response()->json([
            'message' => 'Cita creada correctamente.',
            'data' => $appointment->load([
                'patient:id,first_name,last_name',
                'dentist:id,name',
                'treatment:id,name,duration,price',
            ]),
        ], 201);
    }

    /**
     * Actualizar una cita.
     */
    public function update(Request $request, Appointment $appointment): JsonResponse
    {
        $clinic = $this->getAccessibleClinic(
            $request,
            $appointment->clinic_id
        );

        abort_unless(
            $clinic,
            403,
            'No tienes acceso a esta clínica.'
        );

        $validated = $this->validateAppointment(
            $request,
            $appointment->clinic_id
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
                'dentist_user_id' => $validated['dentist_user_id'],
                'treatment_id' => $treatment->id,
                'treatment_price' => $treatment->price,
                'title' => $treatment->name,
                'start_at' => $validated['start_at'],
                'end_at' => $validated['end_at'],
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
     * Validar los datos de una cita.
     */
    private function validateAppointment(
        Request $request,
        ?int $clinicId = null
    ): array {
        $clinicId = $clinicId ?? $request->input('clinic_id');

        return $request->validate([
            'clinic_id' => [
                Rule::requiredIf($clinicId === null),
                'integer',
                'exists:clinics,id',
            ],

            'patient_id' => [
                'required',
                'integer',
                Rule::exists('patients', 'id')->where(
                    fn ($query) => $query
                        ->where('clinic_id', $clinicId)
                        ->where('status', 'active')
                        ->whereNull('deleted_at')
                ),
            ],

            'dentist_user_id' => [
                'required',
                'integer',
                Rule::exists('clinic_user', 'user_id')->where(
                    fn ($query) => $query
                        ->where('clinic_id', $clinicId)
                        ->where('role', 'dentist')
                ),
            ],

            'treatment_id' => [
                'required',
                'integer',
                Rule::exists('treatments', 'id')->where(
                    fn ($query) => $query
                        ->where('clinic_id', $clinicId)
                        ->where('is_active', true)
                ),
            ],

            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],

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

            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
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