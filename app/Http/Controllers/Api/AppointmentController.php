<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Clinic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class AppointmentController extends Controller
{
    /**
     * Obtener las clínicas accesibles por el usuario y sus citas.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'clinic_id' => ['sometimes', 'integer', 'exists:clinics,id'],
            'start' => ['sometimes', 'date'],
            'end' => ['sometimes', 'date', 'after:start'],
        ]);

        $userId = $request->user()->id;

        // Obtener las clínicas a las que pertenece el usuario.
        $clinics = Clinic::query()
            ->whereHas('users', function ($query) use ($userId) {
                $query->where('users.id', $userId);
            })
            ->get();

        // Si el usuario no tiene clínicas disponibles.
        if ($clinics->isEmpty()) {
            return response()->json([
                'clinics' => [],
                'data' => [],
            ]);
        }

        // Si se especificó una clínica, verificar que sea accesible.
        $clinicId = $validated['clinic_id'] ?? $clinics->first()->id;

        $clinic = $clinics->firstWhere('id', $clinicId);

        abort_unless(
            $clinic,
            403,
            'No tienes acceso a esta clínica.'
        );

        // Consultar las citas de la clínica seleccionada.
        $query = Appointment::query()
            ->where('clinic_id', $clinicId)
            ->with([
                'patient',
                'dentist:id,name',
            ])
            ->orderBy('start_at');

        // Citas que se superponen con el inicio del periodo.
        if (isset($validated['start'])) {
            $query->where('end_at', '>', $validated['start']);
        }

        // Citas que comienzan antes del final del periodo.
        if (isset($validated['end'])) {
            $query->where('start_at', '<', $validated['end']);
        }

        $appointments = $query->get();

        return response()->json([
            'clinics' => $clinics,
            'selected_clinic_id' => $clinic->id,
            'data' => $appointments->map(
                function (Appointment $appointment) {
                    return [
                        'id' => $appointment->id,
                        'clinic_id' => $appointment->clinic_id,
                        'patient_id' => $appointment->patient_id,
                        'dentist_user_id' => $appointment->dentist_user_id,
                        'title' => $appointment->title,
                        'start_at' => $appointment->start_at?->toISOString(),
                        'end_at' => $appointment->end_at?->toISOString(),
                        'status' => $appointment->status,
                        'notes' => $appointment->notes,
                        'patient' => $appointment->patient,
                        'dentist' => $appointment->dentist,
                        'created_at' => $appointment->created_at,
                        'updated_at' => $appointment->updated_at,
                    ];
                }
            ),
        ]);
    }
}