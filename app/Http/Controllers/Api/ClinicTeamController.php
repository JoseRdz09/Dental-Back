<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Models\User;

class ClinicTeamController extends Controller
{
    
    /**
     * Consultar los integrantes de una clínica.
     * Solo el owner de la organización o el clinic_admin de la clínica.
     */
    public function index(
        Request $request,
        int $clinicId
    ): JsonResponse {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Debes iniciar sesión.',
            ], 401);
        }

        // Verificar si es propietario de la organización de la clínica.
        $isOwner = DB::table('clinics as c')
            ->join(
                'organization_user as ou',
                'ou.organization_id',
                '=',
                'c.organization_id'
            )
            ->where('c.id', $clinicId)
            ->where('ou.user_id', $user->id)
            ->where('ou.role', 'owner')
            ->exists();

        // Verificar si es administrador de esta clínica.
        $isClinicAdmin = DB::table('clinic_user')
            ->where('clinic_id', $clinicId)
            ->where('user_id', $user->id)
            ->where('role', 'clinic_admin')
            ->exists();

        // Permitir únicamente al owner o al clinic_admin.
        if (!$isOwner && !$isClinicAdmin) {
            return response()->json([
                'message' => 'No tienes permisos para consultar el equipo de esta clínica.',
            ], 403);
        }

        // Obtener los integrantes del equipo.
        $team = DB::table('clinic_user as cu')
            ->join('users as u', 'u.id', '=', 'cu.user_id')
            ->where('cu.clinic_id', $clinicId)
            ->where('u.is_active', true)
            ->select([
                'cu.clinic_id',
                'cu.user_id',
                'u.name',
                'u.email',
                'cu.role',
                'cu.created_at',
            ])
            ->orderBy('u.name')
            ->get();

        return response()->json([
            'clinic_id' => $clinicId,
            'total' => $team->count(),
            'data' => $team,
        ]);
    }

    
    /**
     * Actualizar un integrante del equipo clínico.
     */
    public function update(
        Request $request,
        int $clinicId,
        int $userId
    ): JsonResponse {
        $authenticatedUser = $request->user();

        if (!$authenticatedUser) {
            return response()->json([
                'message' => 'Debes iniciar sesión.',
            ], 401);
        }

        if (!$authenticatedUser->is_active) {
            return response()->json([
                'message' => 'Esta cuenta está desactivada.',
            ], 403);
        }

        // Verificar si es propietario de la organización.
        $isOwner = DB::table('clinics as c')
            ->join(
                'organization_user as ou',
                'ou.organization_id',
                '=',
                'c.organization_id'
            )
            ->where('c.id', $clinicId)
            ->where('ou.user_id', $authenticatedUser->id)
            ->where('ou.role', 'owner')
            ->exists();

        // Verificar si es administrador de esta clínica.
        $isClinicAdmin = DB::table('clinic_user')
            ->where('clinic_id', $clinicId)
            ->where('user_id', $authenticatedUser->id)
            ->where('role', 'clinic_admin')
            ->exists();

        // Permitir únicamente al owner o al clinic_admin.
        if (!$isOwner && !$isClinicAdmin) {
            return response()->json([
                'message' => 'No tienes permisos para administrar el equipo de esta clínica.',
            ], 403);
        }

        // Comprobar que el integrante pertenezca a la clínica.
        $targetMember = DB::table('clinic_user')
            ->where('clinic_id', $clinicId)
            ->where('user_id', $userId)
            ->first();

        if (!$targetMember) {
            return response()->json([
                'message' => 'El usuario no pertenece a esta clínica.',
            ], 404);
        }

        // Evitar que un administrador modifique su propio rol
        // o el rol de otro administrador.
        if (!$isOwner) {
            if (
                $userId === (int) $authenticatedUser->id ||
                $targetMember->role === 'clinic_admin'
            ) {
                return response()->json([
                    'message' => 'El administrador no puede modificar su propio perfil ni el de otro administrador.',
                ], 403);
            }
        }

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'role' => [
                'required',
                Rule::in([
                    'clinic_admin',
                    'dentist',
                    'receptionist',
                ]),
            ],
            'generate_temporary_password' => [
                'sometimes',
                'boolean',
            ],
        ]);

        // El administrador no puede asignar ni cambiar el rol clinic_admin.
        if (
            !$isOwner &&
            $validated['role'] === 'clinic_admin'
        ) {
            return response()->json([
                'message' => 'Solo el propietario puede asignar el rol de administrador de clínica.',
            ], 403);
        }

        $generateTemporaryPassword = (bool) (
            $validated['generate_temporary_password'] ?? false
        );

        // Generar contraseña temporal solo si el integrante
        // ya es clinic_admin y conserva ese rol.
        if (
            $generateTemporaryPassword &&
            (
                $targetMember->role !== 'clinic_admin' ||
                $validated['role'] !== 'clinic_admin'
            )
        ) {
            return response()->json([
                'message' => 'Solo puedes generar una contraseña temporal para un integrante que tenga y conserve el rol Administrador de clínica.',
            ], 403);
        }

        // El administrador no puede generar contraseñas temporales.
        if ($generateTemporaryPassword && !$isOwner) {
            return response()->json([
                'message' => 'Solo el propietario puede generar contraseñas temporales.',
            ], 403);
        }

        $temporaryPassword = null;

        DB::transaction(function () use (
            $validated,
            $clinicId,
            $userId,
            $generateTemporaryPassword,
            &$temporaryPassword
        ) {
            $userData = [
                'name' => $validated['name'],
                'email' => $validated['email'],
                'updated_at' => now(),
            ];

            if ($generateTemporaryPassword) {
                $temporaryPassword = Str::random(32);

                $userData['password'] = Hash::make(
                    $temporaryPassword
                );

                $userData['must_change_password'] = true;
            }

            DB::table('users')
                ->where('id', $userId)
                ->update($userData);

            // El puesto se guarda exclusivamente en clinic_user.
            DB::table('clinic_user')
                ->where('clinic_id', $clinicId)
                ->where('user_id', $userId)
                ->update([
                    'role' => $validated['role'],
                    'updated_at' => now(),
                ]);
        });

        return response()->json([
            'message' => $generateTemporaryPassword
                ? 'Integrante actualizado y contraseña temporal generada correctamente.'
                : 'Integrante actualizado correctamente.',
            'temporary_password' => $temporaryPassword,
        ]);
    }


    /**
     * Crear un nuevo integrante del equipo clínico.
     */
    public function store(
        Request $request,
        int $clinicId
    ): JsonResponse {
        $authenticatedUser = $request->user();

        if (!$authenticatedUser) {
            return response()->json([
                'message' => 'Debes iniciar sesión.',
            ], 401);
        }

        if (!$authenticatedUser->is_active) {
            return response()->json([
                'message' => 'Esta cuenta está desactivada.',
            ], 403);
        }

        // Verificar si es propietario de la organización.
        $isOwner = DB::table('clinics as c')
            ->join(
                'organization_user as ou',
                'ou.organization_id',
                '=',
                'c.organization_id'
            )
            ->where('c.id', $clinicId)
            ->where('ou.user_id', $authenticatedUser->id)
            ->where('ou.role', 'owner')
            ->exists();

        // Verificar si es administrador de esta clínica.
        $isClinicAdmin = DB::table('clinic_user')
            ->where('clinic_id', $clinicId)
            ->where('user_id', $authenticatedUser->id)
            ->where('role', 'clinic_admin')
            ->exists();

        // Permitir el acceso al propietario o al administrador.
        if (!$isOwner && !$isClinicAdmin) {
            return response()->json([
                'message' => 'No tienes permisos para administrar este equipo.',
            ], 403);
        }

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'email' => [
                'required',
                'email',
                'max:255',
                'unique:users,email',
            ],
            'role' => [
                'required',
                Rule::in([
                    'clinic_admin',
                    'dentist',
                    'receptionist',
                ]),
            ],
        ]);

        $isClinicAdmin = $validated['role'] === 'clinic_admin';

        $temporaryPassword = null;

        $userId = DB::transaction(function () use (
            $validated,
            $clinicId,
            $isClinicAdmin,
            &$temporaryPassword
        ) {
            // Generar contraseña temporal solo para clinic_admin.
            $passwordToStore = Str::random(64);

            if ($isClinicAdmin) {
                $temporaryPassword = Str::random(32);
                $passwordToStore = $temporaryPassword;
            }

            // Crear la cuenta sin guardar un rol global en users.
            $userId = DB::table('users')->insertGetId([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($passwordToStore),
                'is_active' => true,
                'must_change_password' => $isClinicAdmin,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Guardar el puesto únicamente en clinic_user.
            DB::table('clinic_user')->insert([
                'clinic_id' => $clinicId,
                'user_id' => $userId,
                'role' => $validated['role'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $userId;
        });

        return response()->json([
            'message' => $isClinicAdmin
                ? 'Integrante creado correctamente. Se generó una contraseña temporal.'
                : 'Integrante creado correctamente.',
            'user_id' => $userId,
            'temporary_password' => $temporaryPassword,
        ], 201);
    }

    /**
     * Eliminar lógicamente un usuario.
     *
     * Puede hacerlo un administrador de una clínica
     * compartida o el propietario de una organización
     * compartida con el usuario objetivo.
     */
    
    public function destroy(
        Request $request,
        int $userId
    ): JsonResponse {
        $authenticatedUser = $request->user();

        if (!$authenticatedUser) {
            return response()->json([
                'message' => 'Debes iniciar sesión.',
            ], 401);
        }

        if (!$authenticatedUser->is_active) {
            return response()->json([
                'message' => 'Tu cuenta está desactivada.',
            ], 403);
        }

        if ((int) $authenticatedUser->id === $userId) {
            return response()->json([
                'message' => 'No puedes eliminar tu propia cuenta.',
            ], 403);
        }

        $targetUser = User::find($userId);

        if (!$targetUser) {
            return response()->json([
                'message' => 'El usuario no existe o ya fue eliminado.',
            ], 404);
        }

        // ¿Es administrador de una clínica donde está el usuario objetivo?
        $canDeleteAsClinicAdmin = DB::table('clinic_user as actor')
            ->join(
                'clinic_user as target',
                'actor.clinic_id',
                '=',
                'target.clinic_id'
            )
            ->where('actor.user_id', $authenticatedUser->id)
            ->where('actor.role', 'clinic_admin')
            ->where('target.user_id', $targetUser->id)
            ->exists();

        // ¿Es propietario de una organización que contiene
        // una clínica donde está el usuario objetivo?
        $canDeleteAsOwner = DB::table('organization_user as owner')
            ->join(
                'clinic_user as target',
                'target.clinic_id',
                '=',
                'target.clinic_id'
            )
            ->join(
                'clinics',
                'clinics.id',
                '=',
                'target.clinic_id'
            )
            ->where('owner.user_id', $authenticatedUser->id)
            ->where('owner.role', 'owner')
            ->where('target.user_id', $targetUser->id)
            ->whereColumn(
                'clinics.organization_id',
                'owner.organization_id'
            )
            ->exists();

        if (!$canDeleteAsClinicAdmin && !$canDeleteAsOwner) {
            return response()->json([
                'message' => 'No tienes permisos para eliminar este usuario.',
            ], 403);
        }

        DB::transaction(function () use ($targetUser) {
            $targetUser->is_active = false;
            $targetUser->save();

            $targetUser->delete();
        });

        return response()->json([
            'message' => 'Usuario eliminado correctamente.',
            'user_id' => $targetUser->id,
            'deleted_at' => $targetUser->deleted_at,
        ]);
    }

}