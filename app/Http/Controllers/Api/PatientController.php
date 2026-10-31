<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class PatientController extends Controller
{
    /**
     * Campos que se pueden devolver al frontend.
     */
    private const PATIENT_FIELDS = [
        'id',
        'clinic_id',
        'first_name',
        'last_name',
        'phone',
        'birth_date',
        'gender',
        'address',
        'emergency_contact_name',
        'emergency_contact_phone',
        'status',
        'notes',
        'record_clinical',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * Obtener la clínica administrada por el usuario autenticado.
     */
    private function getManagedClinic(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return null;
        }

        return $user->clinics()
            ->wherePivot('role', 'clinic_admin')
            ->first();
    }

    /**
     * Listar pacientes activos e inactivos.
     * Excluye automáticamente los eliminados mediante Soft Delete.
     *
     * GET /api/patients?search=maria&status=active&page=1
     */
    public function index(Request $request): JsonResponse
    {
        $clinic = $this->getManagedClinic($request);

        if (!$clinic) {
            return response()->json([
                'message' => 'No tienes una clínica asignada como administrador.',
            ], 403);
        }

        $validated = $request->validate([
            'search' => [
                'nullable',
                'string',
                'max:100',
            ],
            'status' => [
                'nullable',
                Rule::in(['all', 'active', 'inactive']),
            ],
            'page' => [
                'sometimes',
                'integer',
                'min:1',
            ],
        ]);

        $search = trim($validated['search'] ?? '');
        $status = $validated['status'] ?? 'all';

        // Laravel excluye automáticamente los registros eliminados.
        $query = Patient::query()
            ->where('clinic_id', $clinic->id)
            ->select(self::PATIENT_FIELDS);

        // Filtrar por estado.
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        
        // Buscar por el inicio de cada palabra del nombre o apellido.
        // Ignora mayúsculas, minúsculas y acentos.
        if ($search !== '') {
            $words = preg_split(
                '/\s+/u',
                mb_strtolower($search),
                -1,
                PREG_SPLIT_NO_EMPTY
            );

            $query->where(function ($builder) use ($words) {
                foreach ($words as $word) {
                    $pattern = $word . '%';

                    $builder->where(function ($subQuery) use ($pattern) {
                        $subQuery
                            ->whereRaw(
                                'unaccent(LOWER(first_name)) LIKE unaccent(?)',
                                [$pattern]
                            )
                            ->orWhereRaw(
                                'unaccent(LOWER(last_name)) LIKE unaccent(?)',
                                [$pattern]
                            );
                    });
                }
            });
        }


        // Ordenar pacientes.
        $query->orderByDesc('id');

        // Obtener los pacientes para la tabla.
        $paginator = $query->paginate(15)->withQueryString();

        // Obtener el total general de pacientes de la clínica.
        $totalPatients = Patient::query()
            ->where('clinic_id', $clinic->id)
            ->count();

        $response = $paginator->toArray();

        // Reemplazar el total del paginador por el total general.
        $response['total'] = $totalPatients;

        return response()->json($response);
    }

    /**
     * Mostrar un paciente.
     * GET /api/patients/{patient}
     */
    public function show(
        Request $request,
        Patient $patient
    ): JsonResponse {

        if (!$this->hasClinicAccess($request, $patient->clinic_id)) {
            return response()->json([
                'message' => 'No tienes acceso a este paciente.',
            ], 403);
        }

        return response()->json([
            'patient' => $patient->only(self::PATIENT_FIELDS),
        ]);
    }

    /**
     * Registrar un paciente.
     * POST /api/patients
     */
    public function store(Request $request): JsonResponse
    {
        $clinic = $this->getManagedClinic($request);

        if (!$clinic) {
            return response()->json([
                'message' => 'No tienes una clínica asignada como administrador.',
            ], 403);
        }

        $validated = $request->validate([
            'first_name' => [
                'required',
                'string',
                'max:100',
            ],

            'last_name' => [
                'required',
                'string',
                'max:100',
            ],

            'phone' => [
                'nullable',
                'string',
                'max:30',
            ],

            'birth_date' => [
                'nullable',
                'date',
                'before_or_equal:today',
            ],

            'gender' => [
                'sometimes',
                'required',
                Rule::in([
                    'Masculino',
                    'Femenino',
                    'Sin especificar',
                ]),
            ],

            'address' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'emergency_contact_name' => [
                'nullable',
                'string',
                'max:150',
            ],

            'emergency_contact_phone' => [
                'nullable',
                'string',
                'max:30',
            ],

            'status' => [
                'sometimes',
                'required',
                Rule::in(['active', 'inactive']),
            ],

            'notes' => [
                'nullable',
                'string',
            ],
        ]);

        $patient = DB::transaction(function () use ($validated, $clinic) {

            // Obtener el siguiente ID de PostgreSQL.
            $nextId = DB::selectOne(
                "SELECT nextval(pg_get_serial_sequence('patients', 'id')) AS id"
            )->id;

            // Generar automáticamente el número de expediente.
            $recordClinical = 'EXP-' . str_pad(
                (string) $nextId,
                6,
                '0',
                STR_PAD_LEFT
            );

            $patient = new Patient();

            $patient->forceFill([
                ...$validated,
                'id' => $nextId,
                'clinic_id' => $clinic->id,
                'record_clinical' => $recordClinical,
                'status' => $validated['status'] ?? 'active',
            ]);

            $patient->save();

            return $patient;
        });

        return response()->json([
            'message' => 'Paciente registrado correctamente.',
            'patient' => $patient
                ->fresh()
                ->only(self::PATIENT_FIELDS),
        ], 201);
    }

    /**
     * Actualizar un paciente.
     * PUT/PATCH /api/patients/{patient}
     */
    public function update(
        Request $request,
        Patient $patient
    ): JsonResponse {

        if (!$this->hasClinicAccess($request, $patient->clinic_id)) {
            return response()->json([
                'message' => 'No tienes acceso a este paciente.',
            ], 403);
        }

        $validated = $request->validate([
            'first_name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
            ],

            'last_name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
            ],

            'phone' => [
                'sometimes',
                'required',
                'string',
                'max:13',
            ],

            'birth_date' => [
                'sometimes',
                'nullable',
                'date',
                'before_or_equal:today',
            ],

            'gender' => [
                'sometimes',
                'nullable',
                Rule::in([
                    'Masculino',
                    'Femenino',
                    'Sin especificar',
                ]),
            ],

            'address' => [
                'sometimes',
                'nullable',
                'string',
                'max:1000',
            ],

            'emergency_contact_name' => [
                'sometimes',
                'nullable',
                'string',
                'max:150',
            ],

            'emergency_contact_phone' => [
                'sometimes',
                'nullable',
                'string',
                'max:13',
            ],

            'status' => [
                'sometimes',
                'required',
                Rule::in(['active', 'inactive']),
            ],

            'notes' => [
                'sometimes',
                'nullable',
                'string',
            ],
        ]);

        // No se permite modificar ID, clínica ni expediente.
        $patient->fill($validated);
        $patient->save();

        return response()->json([
            'message' => 'Paciente actualizado correctamente.',
            'patient' => $patient
                ->fresh()
                ->only(self::PATIENT_FIELDS),
        ]);
    }

    /**
     * Desactivar un paciente.
     * DELETE /api/patients/{patient}
     *
     * No aplica Soft Delete.
     * Conserva el expediente y todo su historial clínico.
     */
    public function destroy(
        Request $request,
        Patient $patient
    ): JsonResponse {

        if (!$this->hasClinicAccess($request, $patient->clinic_id)) {
            return response()->json([
                'message' => 'No tienes acceso a este paciente.',
            ], 403);
        }

        $patient->status = 'inactive';
        $patient->save();

        return response()->json([
            'message' => 'Paciente desactivado correctamente.',
            'patient' => $patient
                ->fresh()
                ->only(self::PATIENT_FIELDS),
        ]);
    }

    /**
     * Verificar acceso del usuario a la clínica.
     */
    private function hasClinicAccess(
        Request $request,
        int $clinicId
    ): bool {

        $user = $request->user();

        if (!$user) {
            return false;
        }

        return $user->clinics()
            ->where('clinics.id', $clinicId)
            ->exists();
    }
}