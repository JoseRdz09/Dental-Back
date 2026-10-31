<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Construir la respuesta del usuario autenticado,
     * incluyendo sus organizaciones y clínicas.
     */
    private function userResponse($user): array
    {
        // Organizaciones a las que pertenece el usuario.
        $organizations = $user->organizations()
            ->withPivot('role')
            ->get()
            ->map(function ($organization) {
                return [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'role' => $organization->pivot->role,
                ];
            })
            ->values();

        // Clínicas a las que pertenece el usuario.
        $clinics = $user->clinics()
            ->withPivot('role')
            ->get()
            ->map(function ($clinic) {
                return [
                    'id' => $clinic->id,
                    'name' => $clinic->name,
                    'role' => $clinic->pivot->role,
                ];
            })
            ->values();

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'organizations' => $organizations,
            'clinics' => $clinics,
        ];
    }

    /**
     * Iniciar sesión.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (!Auth::guard('web')->attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales no son correctas.'],
            ]);
        }

        $user = Auth::guard('web')->user();

        // Comprobar que la cuenta esté activa.
        if (!$user->is_active) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'message' => 'Esta cuenta está desactivada.',
            ], 403);
        }

        // Regenerar el identificador de sesión tras autenticar.
        $request->session()->regenerate();

        return response()->json([
            'message' => 'Inicio de sesión exitoso.',
            ...$this->userResponse($user),
        ]);
    }

    /**
     * Consultar al usuario de la sesión actual.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'No hay una sesión autenticada.',
            ], 401);
        }

        if (!$user->is_active) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'message' => 'Esta cuenta está desactivada.',
            ], 403);
        }

        return response()->json($this->userResponse($user));
    }

    /**
     * Cerrar sesión.
     */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Sesión cerrada correctamente.',
        ]);
    }
}