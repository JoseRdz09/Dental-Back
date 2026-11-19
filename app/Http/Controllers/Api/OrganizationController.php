<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class OrganizationController extends Controller
{
    /**
     * Verificar que el usuario sea owner de la organización.
     */
    private function authorizeOwner(
        Request $request,
        Organization $organization
    ): bool {
        return DB::table('organization_user')
            ->where('organization_id', $organization->id)
            ->where('user_id', $request->user()->id)
            ->where('role', 'owner')
            ->exists();
    }

    /**
     * Consultar los datos de una organización.
     */
    public function show(
        Request $request,
        Organization $organization
    ): JsonResponse {
        if (!$this->authorizeOwner($request, $organization)) {
            return response()->json([
                'message' => 'No tienes permisos para consultar esta organización.',
            ], 403);
        }

        return response()->json([
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'logo_url' => $organization->logo_url,
            ],
        ]);
    }

    /**
     * Actualizar los datos de una organización.
     */
    public function update(
        Request $request,
        Organization $organization
    ): JsonResponse {
        if (!$this->authorizeOwner($request, $organization)) {
            return response()->json([
                'message' => 'No tienes permisos para modificar esta organización.',
            ], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],

            'logo_file' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
            ],

            'remove_logo' => [
                'nullable',
                'boolean',
            ],
        ]);

        if ($request->hasFile('logo_file') &&
            $request->boolean('remove_logo')) {
            return response()->json([
                'message' => 'No puedes subir y eliminar el logo al mismo tiempo.',
            ], 422);
        }

        $oldLogoUrl = $organization->logo_url;
        $newLogoPath = null;

        // Subir nuevo logo.
        if ($request->hasFile('logo_file')) {
            $newLogoPath = $request->file('logo_file')
                ->store('organizations/logos', 'public');

            if (!$newLogoPath) {
                return response()->json([
                    'message' => 'No fue posible guardar el logo.',
                ], 500);
            }

            $organization->logo_url = Storage::disk('public')
                ->url($newLogoPath);
        }

        // Eliminar logo actual.
        elseif ($request->boolean('remove_logo')) {
            $organization->logo_url = null;
        }

        $organization->name = $validated['name'];

        try {
            $organization->save();
        } catch (\Throwable $e) {
            // Evitar dejar archivos nuevos sin referencia en la BD.
            if ($newLogoPath) {
                Storage::disk('public')->delete($newLogoPath);
            }

            throw $e;
        }

        // Eliminar logo anterior solamente después de guardar correctamente.
        if (
            ($newLogoPath || $request->boolean('remove_logo')) &&
            $oldLogoUrl &&
            $oldLogoUrl !== $organization->logo_url
        ) {
            $this->deleteStoredOrganizationLogo($oldLogoUrl);
        }

        return response()->json([
            'message' => 'Organización actualizada correctamente.',
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'logo_url' => $organization->logo_url,
            ],
        ]);
    }

    /**
     * Eliminar un logo anterior almacenado localmente.
     */
    private function deleteStoredOrganizationLogo(string $logoUrl): void
    {
        $path = parse_url($logoUrl, PHP_URL_PATH);

        if (!$path) {
            return;
        }

        $storagePrefix = '/storage/';

        if (!str_starts_with($path, $storagePrefix)) {
            return;
        }

        $relativePath = substr($path, strlen($storagePrefix));

        // Evitar eliminar archivos fuera de la carpeta de logos.
        if (!str_starts_with($relativePath, 'organizations/logos/')) {
            return;
        }

        Storage::disk('public')->delete($relativePath);
    }
}