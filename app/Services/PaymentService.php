<?php
declare(strict_types=1);

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Payment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    /* =====================================================================
     |  PAGO DE UNA CITA (modal de cita)
     | ===================================================================== */

    /**
     * Registra un pago (abono) sobre UNA cita.
     *
     * Llamar dentro de una DB::transaction y, si la cita ya existía,
     * con su fila bloqueada (lockForUpdate).
     *
     * @param array{amount: float|int|string, method: string, reference?: string|null} $data
     */
    public function register(
        Appointment $appointment,
        array $data,
        int $receivedBy,
        ?string $idempotencyKey = null
    ): Payment {
        if ($idempotencyKey !== null) {
            $existing = Payment::query()
                ->where('clinic_id', $appointment->clinic_id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                if ((int) $existing->appointment_id !== (int) $appointment->id) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['El identificador del pago ya fue usado en otra cita.'],
                    ]);
                }

                return $existing;
            }
        }

        $totalCents = $this->toCents($appointment->items()->sum('total'));

        $paidCents = $this->toCents(
            $appointment->payments()->where('status', 'completed')->sum('amount')
        );

        $amountCents = $this->toCents($data['amount']);
        $pendingCents = max($totalCents - $paidCents, 0);

        if ($pendingCents === 0) {
            throw ValidationException::withMessages([
                'payment.amount' => ['La cita no tiene saldo pendiente.'],
            ]);
        }

        if ($amountCents > $pendingCents) {
            throw ValidationException::withMessages([
                'payment.amount' => [
                    'El pago excede el saldo pendiente ($' . number_format($pendingCents / 100, 2) . ').',
                ],
            ]);
        }

        return Payment::create([
            'clinic_id' => $appointment->clinic_id,
            'appointment_id' => $appointment->id,
            'patient_id' => $appointment->patient_id,
            'received_by' => $receivedBy,
            'amount' => $this->money($amountCents),
            'payment_method' => $data['method'],
            'reference' => $data['method'] === 'cash' ? null : ($data['reference'] ?? null),
            'status' => 'completed',
            'paid_at' => now(),
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    /* =====================================================================
     |  CUENTA DEL PACIENTE (deuda general)
     | ===================================================================== */

    /**
     * Citas COMPLETADAS del paciente con saldo pendiente, de la más antigua
     * a la más reciente. Todo en centavos.
     *
     * Subconsultas correlacionadas por cita: solo toca las citas del
     * paciente (no agrega toda la tabla).
     *
     * Si Appointment usa SoftDeletes, agrega "AND a.deleted_at IS NULL".
     *
     * @return array<int, array{id:int,title:?string,start_at:string,total_cents:int,paid_cents:int,balance_cents:int}>
     */
    public function debts(int $clinicId, int $patientId, ?int $onlyAppointmentId = null): array
    {
        $bindings = [$clinicId, $patientId, AppointmentStatus::Completed->value];

        $extra = '';
        if ($onlyAppointmentId !== null) {
            $extra = ' AND a.id = ?';
            $bindings[] = $onlyAppointmentId;
        }

        $rows = DB::select(
            "SELECT d.* FROM (
                SELECT a.id, a.title, a.start_at,
                    COALESCE((SELECT SUM(i.total) FROM appointment_items i
                              WHERE i.appointment_id = a.id), 0) AS total,
                    COALESCE((SELECT SUM(p.amount) FROM payments p
                              WHERE p.appointment_id = a.id AND p.status = 'completed'), 0) AS paid
                FROM appointments a
                WHERE a.clinic_id = ? AND a.patient_id = ? AND a.status = ?{$extra}
            ) d
            WHERE d.total - d.paid > 0
            ORDER BY d.start_at, d.id",
            $bindings
        );

        return array_map(function ($r): array {
            $total = $this->toCents($r->total);
            $paid = $this->toCents($r->paid);

            return [
                'id' => (int) $r->id,
                'title' => $r->title,
                'start_at' => (string) $r->start_at,
                'total_cents' => $total,
                'paid_cents' => $paid,
                'balance_cents' => max($total - $paid, 0),
            ];
        }, $rows);
    }

    /**
     * Registra un abono general: se reparte de la cita más antigua a la más
     * reciente (o se aplica a una cita concreta). Crea UNA fila de pago por
     * cita afectada, todas con el mismo payment_group_id.
     *
     * @param array{amount: float|int|string, method: string, reference?: string|null} $data
     * @return Collection<int, Payment>
     */
    public function registerForPatient(
        int $clinicId,
        int $patientId,
        array $data,
        int $receivedBy,
        ?string $idempotencyKey = null,
        ?int $appointmentId = null
    ): Collection {
        return DB::transaction(function () use (
            $clinicId, $patientId, $data, $receivedBy, $idempotencyKey, $appointmentId
        ) {
            // 1. Bloqueo (siempre en el mismo orden: sin deadlocks) para que
            //    dos cobros simultáneos no sobrepasen el adeudo.
            Appointment::query()
                ->where('clinic_id', $clinicId)
                ->where('patient_id', $patientId)
                ->where('status', AppointmentStatus::Completed->value)
                ->orderBy('start_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');

            // 2. Idempotencia (después del bloqueo, así un reintento
            //    concurrente espera y ve el pago ya creado).
            //    La clave se guarda solo en la primera fila del grupo.
            if ($idempotencyKey !== null) {
                $first = Payment::query()
                    ->where('clinic_id', $clinicId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($first) {
                    if ((int) $first->patient_id !== $patientId) {
                        throw ValidationException::withMessages([
                            'idempotency_key' => ['El identificador del pago ya fue usado.'],
                        ]);
                    }

                    return $first->payment_group_id
                        ? Payment::query()
                            ->where('payment_group_id', $first->payment_group_id)
                            ->orderBy('id')
                            ->get()
                        : collect([$first]);
                }
            }

            // 3. Deuda actual.
            $debts = $this->debts($clinicId, $patientId, $appointmentId);

            if ($debts === []) {
                throw ValidationException::withMessages([
                    $appointmentId !== null ? 'appointment_id' : 'amount' => [
                        $appointmentId !== null
                            ? 'La cita seleccionada no tiene saldo pendiente.'
                            : 'El paciente no tiene adeudos.',
                    ],
                ]);
            }

            $debtCents = array_sum(array_column($debts, 'balance_cents'));
            $amountCents = $this->toCents($data['amount']);

            if ($amountCents > $debtCents) {
                throw ValidationException::withMessages([
                    'amount' => [
                        'El pago excede el adeudo ($' . number_format($debtCents / 100, 2) . ').',
                    ],
                ]);
            }

            // 4. Reparto FIFO.
            $group = (string) Str::uuid();
            $remaining = $amountCents;
            $created = collect();

            foreach ($debts as $debt) {
                if ($remaining <= 0) {
                    break;
                }

                $apply = min($debt['balance_cents'], $remaining);

                $created->push(Payment::create([
                    'clinic_id' => $clinicId,
                    'appointment_id' => $debt['id'],
                    'patient_id' => $patientId,
                    'received_by' => $receivedBy,
                    'amount' => $this->money($apply),
                    'payment_method' => $data['method'],
                    'reference' => $data['method'] === 'cash' ? null : ($data['reference'] ?? null),
                    'status' => 'completed',
                    'paid_at' => now(),
                    'payment_group_id' => $group,
                    'idempotency_key' => $created->isEmpty() ? $idempotencyKey : null,
                ]));

                $remaining -= $apply;
            }

            return $created;
        });
    }

    /**
     * Cancela un pago (no se borra). Si pertenece a un grupo, se cancela
     * el grupo completo: para el usuario es un solo cobro.
     *
     * @return int cantidad de filas canceladas
     */
    public function cancel(Payment $payment, int $cancelledBy, string $reason): int
    {
        return DB::transaction(function () use ($payment, $cancelledBy, $reason) {
            $rows = Payment::query()
                ->where('clinic_id', $payment->clinic_id)
                ->where('status', 'completed')
                ->when(
                    $payment->payment_group_id,
                    fn ($q) => $q->where('payment_group_id', $payment->payment_group_id),
                    fn ($q) => $q->whereKey($payment->id)
                )
                ->lockForUpdate()
                ->get(['id']);

            if ($rows->isEmpty()) {
                throw ValidationException::withMessages([
                    'payment' => ['Este pago ya fue cancelado.'],
                ]);
            }

            Payment::query()->whereIn('id', $rows->pluck('id'))->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $cancelledBy,
                'cancellation_reason' => $reason,
                'updated_at' => now(),
            ]);

            return $rows->count();
        });
    }

    /* =====================================================================
     |  DINERO
     | ===================================================================== */

    /** Dinero en centavos enteros: evita errores de punto flotante. */
    public function toCents(mixed $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    public function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }


    /**NUEVOOOOOOO
     * Cuentas por cobrar de toda una clínica.
     *
     * Devuelve únicamente pacientes que tienen citas completadas
     * con saldo pendiente.
     *
     * Los pagos parciales y los abonos generales se descuentan
     * porque se suman todos los pagos completados por cita.
     */
    public function receivables(int $clinicId): array
    {
        $rows = DB::select(
            "WITH appointment_balances AS (
                SELECT
                    a.id,
                    a.patient_id,
                    a.start_at,
                    COALESCE(i.total, 0) AS total,
                    COALESCE(pay.paid, 0) AS paid
                FROM appointments a

                LEFT JOIN (
                    SELECT
                        appointment_id,
                        SUM(total) AS total
                    FROM appointment_items
                    GROUP BY appointment_id
                ) i ON i.appointment_id = a.id

                LEFT JOIN (
                    SELECT
                        appointment_id,
                        SUM(amount) AS paid
                    FROM payments
                    WHERE status = 'completed'
                    GROUP BY appointment_id
                ) pay ON pay.appointment_id = a.id

                WHERE
                    a.clinic_id = ?
                    AND a.status = ?
            ),

            patient_balances AS (
                SELECT
                    patient_id,
                    SUM(total - paid) AS balance,
                    COUNT(*) AS appointments_count,
                    MIN(start_at) AS oldest_at
                FROM appointment_balances
                WHERE total - paid > 0
                GROUP BY patient_id
            )

            SELECT
                p.id AS patient_id,
                CONCAT_WS(' ', p.first_name, p.last_name) AS patient_name,
                pb.balance,
                pb.appointments_count,
                pb.oldest_at

            FROM patient_balances pb

            INNER JOIN patients p ON p.id = pb.patient_id

            WHERE
                p.clinic_id = ?
                AND p.deleted_at IS NULL

            ORDER BY pb.oldest_at ASC, p.id ASC",

            [
                $clinicId,
                AppointmentStatus::Completed->value,
                $clinicId,
            ]
        );

        $totalCents = 0;

        $patients = array_map(function ($row) use (&$totalCents): array {
            $balanceCents = $this->toCents($row->balance);

            $totalCents += $balanceCents;

            return [
                'patient_id' => (int) $row->patient_id,
                'patient_name' => $row->patient_name,
                'balance' => $balanceCents / 100,
                'appointments_count' => (int) $row->appointments_count,
                'oldest_at' => (string) $row->oldest_at,
            ];
        }, $rows);

        return [
            'total' => $totalCents / 100,
            'patients_count' => count($patients),
            'patients' => $patients,
        ];
    }
}
