<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Clinic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClinicController extends Controller
{
    /**
     * Listar las clínicas asignadas al usuario autenticado.
     */
    public function myClinics(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $clinics = DB::table('clinic_user')
            ->join(
                'clinics',
                'clinics.id',
                '=',
                'clinic_user.clinic_id'
            )
            ->where('clinic_user.user_id', $userId)
            ->select(
                'clinics.id',
                'clinics.name',
                'clinics.email',
                'clinics.phone',
                'clinics.address',
                'clinics.status',
                'clinics.timezone',
            
            )
            ->orderBy('clinics.name')
            ->get();

        return response()->json([
            'data' => $clinics,
        ]);
    }

    /**
     * Actualizar los datos de una clínica.
     */
    public function update(
        Request $request,
        Clinic $clinic
    ): JsonResponse {
        $user = $request->user();

        // Verificar si el usuario es propietario de la organización
        // a la que pertenece la clínica.
        $isOrganizationOwner = $user->role === 'owner'
            && DB::table('organization_user')
                ->where('organization_id', $clinic->organization_id)
                ->where('user_id', $user->id)
                ->where('role', 'owner')
                ->exists();

        if (!$isOrganizationOwner) {
            return response()->json([
                'message' => 'Solo el propietario de la organización puede editar esta clínica.',
            ], 403);
        }

        // Validar los datos recibidos.
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^[0-9]{1,10}$/'],
            'address' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:active,inactive'],
            'timezone' => ['required', 'timezone'],
        ]);

        // Actualizar únicamente los campos permitidos.
        $clinic->update($validated);

        return response()->json([
            'message' => 'Clínica actualizada correctamente.',
            'data' => $clinic->fresh(),
        ]);
    }

}