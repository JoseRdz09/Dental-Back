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
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'clinic_id' => ['required', 'integer', 'exists:clinics,id'],
            'start' => ['sometimes', 'date'],
            'end' => ['sometimes', 'date', 'after:start'],
        ]);

        $userId = $request->user()->id;
        $clinicId = $validated['clinic_id'];

        // Verificar que el usuario tenga acceso a esta clínica.
        $hasAccess = DB::table('clinic_user')
            ->where('clinic_id', $clinicId)
            ->where('user_id', $userId)
            ->exists();

        abort_unless($hasAccess, 403, 'No tienes acceso a esta clínica.');

        $query = Appointment::query()
            ->where('clinic_id', $clinicId)
            ->with([
                'patient',
                'dentist:id,name',
            ])
            ->orderBy('start_at');

        // Filtrar por el periodo visible del calendario.
        if (isset($validated['start'])) {
            $query->where('end_at', '>', $validated['start']);
        }

        if (isset($validated['end'])) {
            $query->where('start_at', '<', $validated['end']);
        }

        $appointments = $query->get();

        return response()->json([
            'data' => $appointments->map(function (Appointment $appointment) {
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
            }),
        ]);
    }
}