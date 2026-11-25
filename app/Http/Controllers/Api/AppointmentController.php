<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\Treatment;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Carbon\CarbonImmutable;

class AppointmentController extends Controller
{
    private const ITEM_COLUMNS = 'items:id,appointment_id,type,treatment_id,product_id,description,quantity,unit_price,total';

    private const SHOW_ITEM_COLUMNS = 'items:id,appointment_id,type,treatment_id,product_id,description,quantity,unit_price,total,created_at';

    private const MINUTE_INTERVAL = 5;

    public function __construct(private readonly PaymentService $payments)
    {
    }

    /* =====================================================================
     |  ENDPOINTS
     | ===================================================================== */

    /**
     * Clínicas a las que el usuario tiene acceso.
     */
    public function clinics(Request $request): JsonResponse
    {
        $clinics = Clinic::query()
            ->whereHas('users', fn ($q) => $q->where('users.id', $request->user()->id))
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['data' => $clinics]);
    }

    /**
     * Citas de una clínica dentro del rango solicitado.
     * Incluye total / paid_total / balance / payment_status calculados
     * con subconsultas (sin N+1).
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'clinic_id' => ['required', 'integer', 'exists:clinics,id'],
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
        ]);

        $clinic = $this->authorizedClinic($request, (int) $validated['clinic_id']);

        $start = $this->toUtc($validated['start']);
        $end = $this->toUtc($validated['end']);

        $appointments = Appointment::query()
            ->where('clinic_id', $clinic->id)
            // Solapamiento con el rango solicitado.
            ->where('end_at', '>', $start)
            ->where('start_at', '<', $end)
            ->select([
                'id',
                'clinic_id',
                'patient_id',
                'dentist_user_id',
                'title',
                'start_at',
                'end_at',
                'status',
                'notes',
            ])
            ->with($this->relations())
            ->withSum('items as total', 'total')
            ->withSum(
                ['payments as paid_total' => fn ($q) => $q->where('status', 'completed')],
                'amount'
            )
            ->orderBy('start_at')
            ->get()
            ->each(fn (Appointment $a) => $this->presentSummary($a));

        return response()->json(['data' => $appointments]);
    }

    /**
     * Estadísticas independientes del calendario (una sola consulta SQL).
     *
     * - today:    citas de hoy, excepto canceladas.
     * - pending:  citas pendientes desde hoy hasta el domingo.
     * - upcoming: citas confirmadas desde ahora hasta fin de mes.
     * - cancelled: citas canceladas del mes actual.
     *
     * Todas las fechas se calculan con la zona horaria de la clínica.
     * Requiere PostgreSQL (COUNT ... FILTER).
     */
    public function stats(Request $request): JsonResponse
    {
        $clinic = $this->clinicFromRequest($request);

        $now = CarbonImmutable::now($this->clinicTimezone($clinic));

        $todayStart = $now->startOfDay()->utc();
        $tomorrowStart = $now->addDay()->startOfDay()->utc();
        $weekEnd = $now->startOfWeek(Carbon::MONDAY)->addWeek()->utc(); // próximo lunes 00:00
        $monthStart = $now->startOfMonth()->utc();
        $nextMonthStart = $now->startOfMonth()->addMonth()->utc();
        $nowUtc = $now->utc();

        // Límites globales para que el índice (clinic_id, start_at) descarte filas.
        $lowerBound = $monthStart->min($todayStart);
        $upperBound = $weekEnd->max($nextMonthStart);

        $stats = Appointment::query()
            ->toBase()
            ->where('clinic_id', $clinic->id)
            ->where('start_at', '>=', $lowerBound)
            ->where('start_at', '<', $upperBound)
            ->selectRaw(
                '
                COUNT(*) FILTER (WHERE start_at >= ? AND start_at < ? AND status <> ?) AS today,
                COUNT(*) FILTER (WHERE start_at >= ? AND start_at < ? AND status = ?)  AS pending,
                COUNT(*) FILTER (WHERE start_at >= ? AND start_at < ? AND status = ?)  AS upcoming,
                COUNT(*) FILTER (WHERE start_at >= ? AND start_at < ? AND status = ?)  AS cancelled
                ',
                [
                    $todayStart, $tomorrowStart, AppointmentStatus::Cancelled->value,
                    $todayStart, $weekEnd, AppointmentStatus::Pending->value,
                    $nowUtc, $nextMonthStart, AppointmentStatus::Confirmed->value,
                    $monthStart, $nextMonthStart, AppointmentStatus::Cancelled->value,
                ]
            )
            ->first();

        return response()->json([
            'data' => [
                'today' => (int) $stats->today,
                'pending' => (int) $stats->pending,
                'upcoming' => (int) $stats->upcoming,
                'cancelled' => (int) $stats->cancelled,
            ],
        ]);
    }

    /**
     * Dentistas y tratamientos activos de una clínica.
     * Los pacientes se consultan aparte (searchPatients).
     */
    public function options(Request $request): JsonResponse
    {
        $clinic = $this->clinicFromRequest($request);

        $dentists = $clinic->users()
            ->wherePivot('role', 'dentist')
            ->orderBy('users.name')
            ->get(['users.id', 'users.name']);

        $treatments = Treatment::query()
            ->where('clinic_id', $clinic->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'duration', 'price']);

        return response()->json([
            'data' => [
                'dentists' => $dentists,
                'treatments' => $treatments,
            ],
        ]);
    }

    /**
     * Buscar pacientes activos (máximo 10).
     * Requiere PostgreSQL con la extensión pg_trgm.
     */
    public function searchPatients(Request $request, int $clinicId): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $clinic = $this->authorizedClinic($request, $clinicId);

        $search = trim($validated['q']);
        $contains = '%' . addcslashes($search, '%_\\') . '%';
        $fullName = "(first_name || ' ' || last_name)";

        $patients = Patient::query()
            ->where('clinic_id', $clinic->id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->whereRaw("$fullName ILIKE ?", [$contains])
            ->orderByRaw("similarity($fullName, ?) DESC", [$search])
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->limit(10)
            ->get(['id', 'first_name', 'last_name']);

        return response()->json(['data' => $patients]);
    }

    /**
     * Crear una cita con sus conceptos y, opcionalmente, su primer pago.
     * Todo en una sola transacción.
     */
    public function store(Request $request): JsonResponse
    {
        $clinic = $this->clinicFromRequest($request);

        $validated = $request->validate([
            ...$this->appointmentRules($clinic),
            ...$this->itemRules(),
            ...$this->paymentRules(),
        ]);

        // Validaciones independientes de disponibilidad.
        $this->assertValidSchedule($validated, $clinic);
        $this->assertPaymentAllowed($validated);

        $items = $this->buildItems($validated['items'] ?? [], $clinic);

        $appointment = DB::transaction(function () use (
            $request,
            $validated,
            $clinic,
            $items
        ) {
            $dentistId = isset($validated['dentist_user_id'])
                ? (int) $validated['dentist_user_id']
                : null;

            // 1. Bloquear al dentista.
            $this->lockDentistSchedule($clinic, $dentistId);

            // 2. Comprobar disponibilidad después del bloqueo.
            $this->assertDentistAvailable($validated, $clinic);

            // 3. Crear la cita.
            $appointment = Appointment::create([
                'clinic_id' => $clinic->id,
                'patient_id' => $validated['patient_id'],
                'dentist_user_id' => $dentistId,
                'title' => $validated['title']
                    ?? ($items[0]['description'] ?? 'Cita dental'),
                'start_at' => $this->toUtc($validated['start_at']),
                'end_at' => $this->toUtc($validated['end_at']),
                'status' => $validated['status'],
                'notes' => $validated['notes'] ?? null,
            ]);

            // 4. Guardar conceptos.
            $this->insertItems($appointment, $items);

            // 5. Registrar el pago (el total sale de los conceptos guardados).
            if (! empty($validated['payment'])) {
                $this->payments->register(
                    $appointment,
                    $validated['payment'],
                    (int) $request->user()->id,
                    $validated['idempotency_key'] ?? null
                );
            }

            return $appointment;
        });

        return response()->json([
            'message' => 'Cita creada correctamente.',
            'data' => $this->fresh($appointment),
        ], 201);
    }

    /**
     * Comprueba si el dentista tiene disponibilidad.
     *
     * Se debe ejecutar dentro de una transacción,
     * después de bloquear el registro del dentista.
     */
    private function assertDentistAvailable(
        array $data,
        Clinic $clinic,
        ?Appointment $appointment = null
    ): void {
        $dentistId = $data['dentist_user_id'] ?? null;

        // No validar citas sin dentista ni citas canceladas.
        if (
            ! $dentistId ||
            ($data['status'] ?? null) === AppointmentStatus::Cancelled->value
        ) {
            return;
        }

        $newStart = $this->toUtc($data['start_at']);
        $newEnd = $this->toUtc($data['end_at']);

        $conflict = Appointment::query()
            ->where('clinic_id', $clinic->id)
            ->where('dentist_user_id', $dentistId)

            // Las citas canceladas no bloquean.
            ->where('status', '<>', AppointmentStatus::Cancelled->value)

            // Detectar traslapes.
            ->where('start_at', '<', $newEnd)
            ->where('end_at', '>', $newStart)

            // Excluir la cita actual al editar.
            ->when(
                $appointment,
                fn ($query) => $query->where('id', '<>', $appointment->id)
            )
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                'start_at' => [
                    'El dentista seleccionado ya tiene una cita en ese horario. Selecciona otro horario o dentista.',
                ],
            ]);
        }
    }

    /**
     * Serializa las reservas del mismo dentista.
     *
     * El bloqueo se mantiene hasta finalizar la transacción.
     */
    private function lockDentistSchedule(
        Clinic $clinic,
        ?int $dentistId
    ): void {
        if (! $dentistId) {
            return;
        }

        $dentist = DB::table('clinic_user')
            ->where('clinic_id', $clinic->id)
            ->where('user_id', $dentistId)
            ->where('role', 'dentist')
            ->lockForUpdate()
            ->first();

        if (! $dentist) {
            throw ValidationException::withMessages([
                'dentist_user_id' => [
                    'El dentista seleccionado no pertenece a esta clínica.',
                ],
            ]);
        }
    }

    /**
     * Obtener una cita con su información, conceptos y resumen de pagos.
     */
    public function show(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorizedClinic($request, (int) $appointment->clinic_id);

        return response()->json([
            'data' => $this->fresh($appointment, self::SHOW_ITEM_COLUMNS),
        ]);
    }

    /**
     * Actualizar información general de una cita y, opcionalmente,
     * registrar un pago. Todo en una sola transacción.
     */
    public function update(
        Request $request,
        Appointment $appointment
    ): JsonResponse {
        $clinic = $this->authorizedClinic(
            $request,
            (int) $appointment->clinic_id
        );

        $validated = $request->validate([
            ...$this->appointmentRules($clinic),
            ...$this->paymentRules(),
        ]);

        $this->assertValidSchedule($validated, $clinic, $appointment);
        $this->assertPaymentAllowed($validated);

        DB::transaction(function () use (
            $request,
            $validated,
            $clinic,
            $appointment
        ) {
            $dentistId = isset($validated['dentist_user_id'])
                ? (int) $validated['dentist_user_id']
                : null;

            // 1. Bloquear al dentista seleccionado.
            $this->lockDentistSchedule($clinic, $dentistId);

            // 2. Comprobar disponibilidad excluyendo esta cita.
            $this->assertDentistAvailable(
                $validated,
                $clinic,
                $appointment
            );

            // 3. Actualizar.
            $appointment->update([
                'patient_id' => $validated['patient_id'],
                'dentist_user_id' => $dentistId,
                'title' => $validated['title'] ?? $appointment->title,
                'start_at' => $this->toUtc($validated['start_at']),
                'end_at' => $this->toUtc($validated['end_at']),
                'status' => $validated['status'],
                'notes' => $validated['notes'] ?? null,
            ]);

            // 4. Pago: se bloquea la cita para que dos abonos simultáneos
            //    no puedan sobrepasar el saldo.
            if (! empty($validated['payment'])) {
                Appointment::query()
                    ->whereKey($appointment->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->payments->register(
                    $appointment,
                    $validated['payment'],
                    (int) $request->user()->id,
                    $validated['idempotency_key'] ?? null
                );
            }
        });

        return response()->json([
            'message' => 'Cita actualizada correctamente.',
            'data' => $this->fresh($appointment),
        ]);
    }

    /**
     * Agregar conceptos a una cita existente.
     */
    public function addItems(Request $request, Appointment $appointment): JsonResponse
    {
        $clinic = $this->authorizedClinic($request, (int) $appointment->clinic_id);

        $this->assertItemsEditable($appointment);

        $validated = $request->validate($this->itemRules(required: true));

        $items = $this->buildItems($validated['items'], $clinic);

        DB::transaction(fn () => $this->insertItems($appointment, $items));

        return response()->json([
            'message' => 'Conceptos agregados correctamente.',
            'data' => $appointment->load([self::ITEM_COLUMNS]),
        ]);
    }

    /**
     * Eliminar un concepto de una cita.
     */
    public function destroyItem(
        Request $request,
        Appointment $appointment,
        int $itemId
    ): JsonResponse {
        $this->authorizedClinic($request, (int) $appointment->clinic_id);

        $this->assertItemsEditable($appointment);

        $appointment->items()->whereKey($itemId)->firstOrFail()->delete();

        return response()->json(['message' => 'Concepto eliminado correctamente.']);
    }

    /* =====================================================================
     |  ACCESO Y CONTEXTO
     | ===================================================================== */

    /**
     * Devuelve la clínica si el usuario tiene acceso; aborta con 403 si no.
     */
    private function authorizedClinic(Request $request, int $clinicId): Clinic
    {
        return Clinic::query()
            ->whereKey($clinicId)
            ->whereHas('users', fn ($q) => $q->where('users.id', $request->user()->id))
            ->firstOr(fn () => abort(403, 'No tienes acceso a esta clínica.'));
    }

    /**
     * Valida `clinic_id` del request y devuelve la clínica autorizada.
     */
    private function clinicFromRequest(Request $request): Clinic
    {
        $data = $request->validate([
            'clinic_id' => ['required', 'integer', 'exists:clinics,id'],
        ]);

        return $this->authorizedClinic($request, (int) $data['clinic_id']);
    }

    private function clinicTimezone(Clinic $clinic): string
    {
        return $clinic->timezone ?: config('app.timezone', 'UTC');
    }

    /**
     * Convierte una fecha ISO 8601 a UTC (inmutable, sin efectos secundarios).
     */
    private function toUtc(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value)->utc();
    }

    /**
     * Relaciones a cargar con columnas mínimas.
     */
    private function relations(string $itemColumns = self::ITEM_COLUMNS): array
    {
        return [
            'patient:id,first_name,last_name',
            'dentist:id,name',
            $itemColumns,
        ];
    }

    /* =====================================================================
     |  RESUMEN DE PAGOS
     | ===================================================================== */

    /**
     * Carga relaciones + resumen de pagos de una cita individual.
     */
    private function fresh(
        Appointment $appointment,
        string $itemColumns = self::ITEM_COLUMNS
    ): Appointment {
        $appointment->load($this->relations($itemColumns));

        $appointment->loadSum('items as total', 'total');
        $appointment->loadSum(
            ['payments as paid_total' => fn ($q) => $q->where('status', 'completed')],
            'amount'
        );

        return $this->presentSummary($appointment);
    }

    /**
     * Calcula balance y payment_status a partir de `total` y `paid_total`
     * (ya cargados con withSum / loadSum). Todo se maneja en centavos.
     *
     * payment_status: unpaid | partial | paid
     */
    private function presentSummary(Appointment $appointment): Appointment
    {
        $totalCents = $this->payments->toCents($appointment->getAttribute('total'));
        $paidCents = $this->payments->toCents($appointment->getAttribute('paid_total'));
        $balanceCents = max($totalCents - $paidCents, 0);

        $status = $paidCents <= 0
            ? 'unpaid'
            : ($balanceCents > 0 ? 'partial' : 'paid');

        $appointment->setAttribute('total', $totalCents / 100);
        $appointment->setAttribute('paid_total', $paidCents / 100);
        $appointment->setAttribute('balance', $balanceCents / 100);
        $appointment->setAttribute('payment_status', $status);

        return $appointment;
    }

    /* =====================================================================
     |  VALIDACIÓN DE CITAS
     | ===================================================================== */

    private function appointmentRules(Clinic $clinic): array
    {
        return [
            'clinic_id' => ['sometimes', 'integer', Rule::in([$clinic->id])],

            'patient_id' => [
                'required',
                'integer',
                Rule::exists('patients', 'id')->where(
                    fn ($q) => $q
                        ->where('clinic_id', $clinic->id)
                        ->where('status', 'active')
                        ->whereNull('deleted_at')
                ),
            ],

            'dentist_user_id' => [
                'nullable',
                'integer',
                Rule::exists('clinic_user', 'user_id')->where(
                    fn ($q) => $q
                        ->where('clinic_id', $clinic->id)
                        ->where('role', 'dentist')
                ),
            ],

            'title' => ['nullable', 'string', 'max:255'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
            'status' => ['required', Rule::enum(AppointmentStatus::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Reglas del pago (opcional). `received_by` y `clinic_id` nunca vienen
     * del cliente.
     */
    private function paymentRules(): array
    {
        return [
            'payment' => ['nullable', 'array'],
            'payment.amount' => ['required_with:payment', 'numeric', 'gt:0', 'max:9999999999.99'],
            'payment.method' => ['required_with:payment', Rule::in(['cash', 'card', 'transfer'])],
            'payment.reference' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['nullable', 'uuid'],
        ];
    }

    /**
     * Solo se pueden registrar pagos en citas completadas.
     *
     * Verifica que `AppointmentStatus::Completed` sea el nombre real del case
     * en tu enum (el valor debe coincidir con el que guardas en BD).
     */
    private function assertPaymentAllowed(array $data): void
    {
        if (
            ! empty($data['payment'])
            && ($data['status'] ?? null) !== AppointmentStatus::Completed->value
        ) {
            throw ValidationException::withMessages([
                'payment' => ['El pago solo puede registrarse cuando la cita está completada.'],
            ]);
        }
    }

    /**
     * Reglas de horario que dependen de la zona horaria de la clínica.
     *
     * - Intervalos de 5 minutos (solo si la hora cambió).
     * - Inicio posterior a "ahora" (solo si la hora de inicio cambió).
     */
    private function assertValidSchedule(
        array $data,
        Clinic $clinic,
        ?Appointment $appointment = null
    ): void {
        $timezone = $this->clinicTimezone($clinic);

        $newStart = $this->toUtc($data['start_at']);
        $newEnd = $this->toUtc($data['end_at']);

        $startUnchanged = $appointment
            && CarbonImmutable::parse($appointment->start_at)->utc()->startOfMinute()
                ->equalTo($newStart->startOfMinute());

        $endUnchanged = $appointment
            && CarbonImmutable::parse($appointment->end_at)->utc()->startOfMinute()
                ->equalTo($newEnd->startOfMinute());

        $localStart = $newStart->setTimezone($timezone);
        $localEnd = $newEnd->setTimezone($timezone);

        $errors = [];

        if (! $startUnchanged && $localStart->minute % self::MINUTE_INTERVAL !== 0) {
            $errors['start_at'][] = 'La hora de inicio debe utilizar intervalos de 5 minutos.';
        }

        if (! $endUnchanged && $localEnd->minute % self::MINUTE_INTERVAL !== 0) {
            $errors['end_at'][] = 'La hora de finalización debe utilizar intervalos de 5 minutos.';
        }

        if (! $startUnchanged && $localStart->lessThanOrEqualTo(CarbonImmutable::now($timezone))) {
            $errors['start_at'][] = 'La hora de inicio debe ser posterior a la hora actual de la clínica.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /* =====================================================================
     |  CONCEPTOS (ITEMS)
     | ===================================================================== */

    private function itemRules(bool $required = false): array
    {
        return [
            'items' => [
                $required ? 'required' : 'sometimes',
                'array',
                'max:50',
                ...($required ? ['min:1'] : []),
            ],
            'items.*.type' => ['required', Rule::in(['treatment', 'product', 'extra'])],
            'items.*.treatment_id' => ['nullable', 'integer'],
            'items.*.description' => [
                'required_unless:items.*.type,treatment',
                'nullable',
                'string',
                'max:255',
            ],
            'items.*.quantity' => ['sometimes', 'numeric', 'gt:0', 'max:9999'],
            'items.*.unit_price' => [
                'required_unless:items.*.type,treatment',
                'nullable',
                'numeric',
                'min:0',
                'max:99999999',
            ],
        ];
    }

    /**
     * Normaliza los conceptos. El precio de los tratamientos
     * siempre se toma de la base de datos (y queda guardado como snapshot).
     */
    private function buildItems(array $rawItems, Clinic $clinic): array
    {
        if ($rawItems === []) {
            return [];
        }

        // Una sola consulta para todos los tratamientos (evita N+1).
        $treatmentIds = collect($rawItems)
            ->where('type', 'treatment')
            ->pluck('treatment_id')
            ->filter()
            ->unique()
            ->values();

        $treatments = $treatmentIds->isEmpty()
            ? collect()
            : Treatment::query()
                ->where('clinic_id', $clinic->id)
                ->where('is_active', true)
                ->whereIn('id', $treatmentIds)
                ->get(['id', 'name', 'price'])
                ->keyBy('id');

        $items = [];

        foreach ($rawItems as $index => $item) {
            $quantity = (float) ($item['quantity'] ?? 1);

            if ($item['type'] === 'treatment') {
                $treatment = $treatments->get($item['treatment_id'] ?? null);

                if (! $treatment) {
                    throw ValidationException::withMessages([
                        "items.$index.treatment_id" => [
                            'El tratamiento no existe, no pertenece a esta clínica o está inactivo.',
                        ],
                    ]);
                }

                $treatmentId = $treatment->id;
                $description = $treatment->name;
                $unitPrice = (float) $treatment->price;
            } else {
                $treatmentId = null;
                $description = trim($item['description']);
                $unitPrice = (float) $item['unit_price'];
            }

            $items[] = [
                'type' => $item['type'],
                'treatment_id' => $treatmentId,
                'product_id' => null,
                'description' => $description,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total' => round($quantity * $unitPrice, 2),
            ];
        }

        return $items;
    }

    /**
     * Inserción masiva de conceptos (1 consulta en lugar de N).
     *
     * Nota: insert() no dispara eventos de Eloquent ni rellena timestamps,
     * por eso se agregan manualmente.
     */
    private function insertItems(Appointment $appointment, array $items): void
    {
        if ($items === []) {
            return;
        }

        $now = now();

        $appointment->items()->insert(array_map(
            fn (array $item) => $item + [
                'appointment_id' => $appointment->id,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $items
        ));
    }

    /**
     * Impide modificar conceptos de citas completadas o canceladas.
     */
    private function assertItemsEditable(Appointment $appointment): void
    {
        $status = AppointmentStatus::tryFrom((string) $appointment->status);

        if ($status?->isClosed()) {
            throw ValidationException::withMessages([
                'status' => [
                    "No se pueden modificar los conceptos de una cita {$status->value}.",
                ],
            ]);
        }
    }
}
