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
     * Listar las clínicas de las organizaciones
     * a las que pertenece el usuario autenticado.
     */
    public function myClinics(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $clinics = DB::table('clinics')
            ->join(
                'organization_user',
                'organization_user.organization_id',
                '=',
                'clinics.organization_id'
            )
            ->where('organization_user.user_id', $userId)

            // Excluir clínicas eliminadas lógicamente.
            ->whereNull('clinics.deleted_at')

            ->select(
                'clinics.id',
                'clinics.name',
                'clinics.email',
                'clinics.phone',
                'clinics.address',
                'clinics.status',
                'clinics.timezone',
                'clinics.organization_id'
            )
            ->distinct()
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

        // Verificar que el usuario sea owner de la organización
        // a la que pertenece esta clínica.
        $isOrganizationOwner = DB::table('organization_user')
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
            'phone' => [
                'nullable',
                'string',
                'regex:/^[0-9]{1,10}$/',
            ],
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

    
    /**
     * Crear una clínica y asociarla a la organización
     * del propietario autenticado.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        // Verificar que el usuario sea propietario de una organización.
        $organizations = DB::table('organization_user')
            ->where('user_id', $user->id)
            ->where('role', 'owner')
            ->pluck('organization_id');

        if ($organizations->isEmpty()) {
            return response()->json([
                'message' => 'Necesitas ser propietario de una organización para crear clínicas.',
            ], 403);
        }

        // Por ahora, exigir que el propietario tenga una sola organización.
        if ($organizations->count() > 1) {
            return response()->json([
                'message' => 'El usuario tiene varias organizaciones. Debes seleccionar una organización.',
            ], 422);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^[0-9]{1,10}$/'],
            'address' => ['nullable', 'string', 'max:255'],
            'timezone' => ['required', 'timezone'],
        ]);

        $organizationId = $organizations->first();

        // Crear la clínica y asociarla a la organización.
        $clinic = DB::transaction(function () use (
            $validated,
            $organizationId
        ) {
            return Clinic::create([
                ...$validated,
                'organization_id' => $organizationId,
                'status' => 'active',
            ]);
        });

        return response()->json([
            'message' => 'Clínica creada y asociada correctamente a la organización.',
            'data' => $clinic,
        ], 201);
    }

    
    /**
     * Eliminar lógicamente una clínica.
     */
    public function destroy(Request $request, int $clinicId): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'No estás autenticado.',
            ], 401);
        }

        // Buscar la clínica que se desea eliminar.
        $clinic = Clinic::query()->find($clinicId);

        if (!$clinic) {
            return response()->json([
                'message' => 'La clínica no existe o ya fue eliminada.',
            ], 404);
        }

        // Verificar que el usuario sea owner
        // de la organización a la que pertenece la clínica.
        $isOwner = DB::table('organization_user')
            ->where('organization_id', $clinic->organization_id)
            ->where('user_id', $user->id)
            ->where('role', 'owner')
            ->exists();

        if (!$isOwner) {
            return response()->json([
                'message' => 'Solo el propietario de esta organización puede eliminar sus clínicas.',
            ], 403);
        }

        // Eliminación lógica.
        $clinic->delete();

        return response()->json([
            'message' => 'La clínica fue eliminada correctamente.',
        ], 200);
    }



}