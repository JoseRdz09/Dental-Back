<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    private const RECENT_PAYMENTS = 20;

    public function __construct(private readonly PaymentService $payments)
    {
    }

    /**
     * Estado de cuenta: adeudo total + desglose por cita + pagos recientes.
     */
    public function account(Request $request, int $clinicId, int $patientId): JsonResponse
    {
        $clinic = $this->authorizedClinic($request, $clinicId);
        $patient = $this->patientOf($clinic, $patientId);

        return response()->json(['data' => $this->accountPayload($clinic, $patient)]);
    }

    /**
     * Abono general. Se reparte de la cita más antigua a la más reciente,
     * o se aplica a una cita concreta si viene `appointment_id`.
     */
    public function store(Request $request, int $clinicId, int $patientId): JsonResponse
    {
        $clinic = $this->authorizedClinic($request, $clinicId);
        $patient = $this->patientOf($clinic, $patientId);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'method' => ['required', Rule::in(['cash', 'card', 'transfer'])],
            'reference' => ['nullable', 'string', 'max:255'],
            'appointment_id' => ['nullable', 'integer'],
            'idempotency_key' => ['nullable', 'uuid'],
        ]);

        $this->payments->registerForPatient(
            clinicId: (int) $clinic->id,
            patientId: (int) $patient->id,
            data: $validated,
            receivedBy: (int) $request->user()->id, // del usuario autenticado
            idempotencyKey: $validated['idempotency_key'] ?? null,
            appointmentId: isset($validated['appointment_id'])
                ? (int) $validated['appointment_id']
                : null,
        );

        return response()->json([
            'message' => 'Pago registrado correctamente.',
            'data' => $this->accountPayload($clinic, $patient),
        ], 201);
    }

    /**
     * Cancela un pago (si es de un cobro general, se cancela todo el cobro).
     */
    public function cancel(
        Request $request,
        int $clinicId,
        int $patientId,
        Payment $payment
    ): JsonResponse {
        $clinic = $this->authorizedClinic($request, $clinicId);
        $patient = $this->patientOf($clinic, $patientId);

        // El pago debe pertenecer a esta clínica y a este paciente.
        abort_unless(
            (int) $payment->clinic_id === (int) $clinic->id
                && (int) $payment->patient_id === (int) $patient->id,
            404
        );

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $this->payments->cancel(
            $payment,
            (int) $request->user()->id,
            trim($validated['reason'])
        );

        return response()->json([
            'message' => 'Pago cancelado correctamente.',
            'data' => $this->accountPayload($clinic, $patient),
        ]);
    }

    /* =====================================================================
     |  HELPERS
     | ===================================================================== */

    private function accountPayload(Clinic $clinic, Patient $patient): array
    {
        $debts = $this->payments->debts((int) $clinic->id, (int) $patient->id);

        $totalDebt = array_sum(array_column($debts, 'balance_cents'));

        $recent = Payment::query()
            ->where('clinic_id', $clinic->id)
            ->where('patient_id', $patient->id)
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_PAYMENTS)
            ->get([
                'id',
                'appointment_id',
                'amount',
                'payment_method',
                'status',
                'paid_at',
                'reference',
                'payment_group_id',
                'cancellation_reason',
            ]);

        return [
            'patient' => [
                'id' => $patient->id,
                'first_name' => $patient->first_name,
                'last_name' => $patient->last_name,
            ],
            'total_debt' => $totalDebt / 100,
            'appointments' => array_map(fn (array $d) => [
                'id' => $d['id'],
                'title' => $d['title'],
                'start_at' => $d['start_at'],
                'total' => $d['total_cents'] / 100,
                'paid_total' => $d['paid_cents'] / 100,
                'balance' => $d['balance_cents'] / 100,
            ], $debts),
            'payments' => $recent,
        ];
    }

    private function authorizedClinic(Request $request, int $clinicId): Clinic
    {
        return Clinic::query()
            ->whereKey($clinicId)
            ->whereHas('users', fn ($q) => $q->where('users.id', $request->user()->id))
            ->firstOr(fn () => abort(403, 'No tienes acceso a esta clínica.'));
    }

    private function patientOf(Clinic $clinic, int $patientId): Patient
    {
        return Patient::query()
            ->where('clinic_id', $clinic->id)
            ->whereKey($patientId)
            ->firstOrFail(['id', 'first_name', 'last_name']);
    }

    //NUEVOOOO
    /**
     * Listado general de cuentas por cobrar de una clínica.
     */
    public function receivables(Request $request, int $clinicId): JsonResponse
    {
        $clinic = $this->authorizedClinic($request, $clinicId);

        return response()->json([
            'data' => $this->payments->receivables((int) $clinic->id),
        ]);
    }
}
