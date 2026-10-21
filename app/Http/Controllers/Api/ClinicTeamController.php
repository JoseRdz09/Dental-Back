<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ClinicTeamController extends Controller
{
    /**
     * Consultar los integrantes de una clínica.
     */
    public function index(
        Request $request,
        int $clinicId
    ): JsonResponse {
        $user = $request->user();

        // Verificar que el usuario autenticado pertenece a la clínica.
        $hasAccess = DB::table('clinic_user')
            ->where('clinic_id', $clinicId)
            ->where('user_id', $user->id)
            ->exists();

        if (!$hasAccess) {
            return response()->json([
                'message' => 'No tienes acceso al equipo de esta clínica.',
            ], 403);
        }

        $team = DB::table('clinic_user as cu')
            ->join('users as u', 'u.id', '=', 'cu.user_id')
            ->where('cu.clinic_id', $clinicId)
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

        // 1. Verificar que exista una sesión autenticada.
        if (!$authenticatedUser) {
            return response()->json([
                'message' => 'Debes iniciar sesión.',
            ], 401);
        }

        // 2. Verificar el rol global y el estado de la cuenta.
        $isOwner = DB::table('users')
            ->where('id', $authenticatedUser->id)
            ->where('role', 'owner')
            ->where('is_active', true)
            ->exists();

        if (!$isOwner) {
            return response()->json([
                'message' => 'Solo el propietario puede administrar el equipo.',
            ], 403);
        }

        // 3. Verificar que el owner pertenezca a la clínica.
        $belongsToClinic = DB::table('clinic_user')
            ->where('clinic_id', $clinicId)
            ->where('user_id', $authenticatedUser->id)
            ->exists();

        if (!$belongsToClinic) {
            return response()->json([
                'message' => 'No tienes acceso a esta clínica.',
            ], 403);
        }

        // 4. Comprobar que el integrante pertenezca a la clínica.
        $targetMember = DB::table('clinic_user')
            ->where('clinic_id', $clinicId)
            ->where('user_id', $userId)
            ->first();

        if (!$targetMember) {
            return response()->json([
                'message' => 'El usuario no pertenece a esta clínica.',
            ], 404);
        }

        // 5. Validar los datos recibidos.
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

        $generateTemporaryPassword = (bool) (
            $validated['generate_temporary_password'] ?? false
        );

        // 6. Solo un clinic_admin puede recibir una contraseña temporal.
        if ($generateTemporaryPassword) {
            if (
                $targetMember->role !== 'clinic_admin' ||
                $validated['role'] !== 'clinic_admin'
            ) {
                return response()->json([
                    'message' => 'Solo puedes generar una contraseña temporal para un integrante que tenga y conserve el rol Administrador de clinica.',
                ], 403);
            }
        }

        $temporaryPassword = null;

        // 7. Actualizar la cuenta y el integrante dentro de una transacción.
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

            // 8. Generar contraseña solamente cuando se solicite.
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

            // 9. Actualizar el rol en la clínica correcta.
            DB::table('clinic_user')
                ->where('clinic_id', $clinicId)
                ->where('user_id', $userId)
                ->update([
                    'role' => $validated['role'],
                    'updated_at' => now(),
                ]);
        });

        // 10. Devolver la contraseña únicamente si se generó.
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

        // 1. Verificar autenticación.
        if (!$authenticatedUser) {
            return response()->json([
                'message' => 'Debes iniciar sesión.',
            ], 401);
        }

        // 2. Solo el owner global activo puede crear integrantes.
        $canManageTeam = DB::table('users')
            ->join(
                'clinic_user',
                'clinic_user.user_id',
                '=',
                'users.id'
            )
            ->where('users.id', $authenticatedUser->id)
            ->where('users.role', 'owner')
            ->where('users.is_active', true)
            ->where('clinic_user.clinic_id', $clinicId)
            ->exists();

        if (!$canManageTeam) {
            return response()->json([
                'message' => 'No tienes permisos para administrar este equipo.',
            ], 403);
        }

        // 3. Validar los datos.
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

        // Solo se devolverá una contraseña temporal al clinic_admin.
        $temporaryPassword = null;

        $userId = DB::transaction(function () use (
            $validated,
            $clinicId,
            $isClinicAdmin,
            &$temporaryPassword
        ) {
            // 4. Convertir el rol de clínica al rol global.
            $globalRole = match ($validated['role']) {
                'clinic_admin' => 'admin',
                'dentist' => 'dentist',
                'receptionist' => 'receptionist',
            };

            // 5. Solo clinic_admin recibe una contraseña temporal.
            // Para los demás roles se guarda una contraseña aleatoria
            // desconocida para el usuario y no se devuelve al frontend.
            $passwordToStore = Str::random(64);

            if ($isClinicAdmin) {
                $temporaryPassword = Str::random(32);
                $passwordToStore = $temporaryPassword;
            }

            // 6. Crear la cuenta global.
            $userId = DB::table('users')->insertGetId([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($passwordToStore),
                'role' => $globalRole,
                'is_active' => true,
                'must_change_password' => $isClinicAdmin,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 7. Asociar la cuenta a la clínica.
            DB::table('clinic_user')->insert([
                'clinic_id' => $clinicId,
                'user_id' => $userId,
                'role' => $validated['role'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $userId;
        });

        // 8. Devolver la contraseña solo si es clinic_admin.
        return response()->json([
            'message' => $isClinicAdmin
                ? 'Integrante creado correctamente. Se generó una contraseña temporal.'
                : 'Integrante creado correctamente.',
            'user_id' => $userId,
            'temporary_password' => $temporaryPassword,
        ], 201);
    }


}