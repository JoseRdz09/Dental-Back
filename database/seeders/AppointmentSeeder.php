<?php
declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AppointmentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class AppointmentSeeder extends Seeder
{
    private const CLINIC_ID = 2;

    private const PATIENT_MIN_ID = 1;
    private const PATIENT_MAX_ID = 206;

    private const TREATMENT_MIN_ID = 1;
    private const TREATMENT_MAX_ID = 39;

    private const DENTIST_IDS = [4, 5, 13];

    private const TIMEZONE = 'America/Mexico_City';

    private const MARKER = '[SEED:APPOINTMENT_DEMO_V1]';

    public function run(): void
    {
        $now = CarbonImmutable::now(self::TIMEZONE);

        $from = $now->subMonths(4)->startOfDay();
        $until = $now->addDays(30)->startOfDay();

        DB::transaction(function () use ($now, $from, $until) {

            /*
            |--------------------------------------------------------------------------
            | 1. Eliminar los datos generados anteriormente por este Seeder
            |--------------------------------------------------------------------------
            */

            $seededAppointmentIds = DB::table('appointments')
                ->where('clinic_id', self::CLINIC_ID)
                ->where('notes', 'like', '%' . self::MARKER . '%')
                ->pluck('id')
                ->all();

            if (!empty($seededAppointmentIds)) {
                DB::table('payments')
                    ->whereIn('appointment_id', $seededAppointmentIds)
                    ->delete();

                DB::table('appointment_items')
                    ->whereIn('appointment_id', $seededAppointmentIds)
                    ->delete();

                DB::table('appointments')
                    ->whereIn('id', $seededAppointmentIds)
                    ->delete();
            }

            /*
            |--------------------------------------------------------------------------
            | 2. Obtener pacientes activos
            |--------------------------------------------------------------------------
            */

            $patients = DB::table('patients')
                ->where('clinic_id', self::CLINIC_ID)
                ->whereNull('deleted_at')
                ->whereBetween('id', [
                    self::PATIENT_MIN_ID,
                    self::PATIENT_MAX_ID,
                ])
                ->pluck('id')
                ->all();

            /*
            |--------------------------------------------------------------------------
            | 3. Obtener tratamientos activos
            |--------------------------------------------------------------------------
            */

            $treatments = DB::table('treatments')
                ->where('clinic_id', self::CLINIC_ID)
                ->where('is_active', true)
                ->whereBetween('id', [
                    self::TREATMENT_MIN_ID,
                    self::TREATMENT_MAX_ID,
                ])
                ->select('id', 'name', 'duration', 'price')
                ->get()
                ->all();

            /*
            |--------------------------------------------------------------------------
            | 4. Obtener dentistas activos
            |--------------------------------------------------------------------------
            */

            $dentists = DB::table('clinic_user')
            ->join('users', 'users.id', '=', 'clinic_user.user_id')
            ->where('clinic_user.clinic_id', self::CLINIC_ID)
            ->where('clinic_user.role', 'dentist')
            ->where('users.is_active', true)
            ->whereNull('users.deleted_at')
            ->whereIn('clinic_user.user_id', self::DENTIST_IDS)
            ->select('clinic_user.user_id')
            ->get()
            ->all();

            if (empty($patients)) {
                throw new RuntimeException(
                    'No hay pacientes activos disponibles para generar citas.'
                );
            }

            if (empty($treatments)) {
                throw new RuntimeException(
                    'No hay tratamientos activos disponibles para generar citas.'
                );
            }

            if (empty($dentists)) {
                throw new RuntimeException(
                    'No hay dentistas activos disponibles para generar citas.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | 5. Contadores
            |--------------------------------------------------------------------------
            */

            $statusCounts = [
                AppointmentStatus::Pending->value => 0,
                AppointmentStatus::Confirmed->value => 0,
                AppointmentStatus::Completed->value => 0,
                AppointmentStatus::Cancelled->value => 0,
            ];

            $generatedTotal = 0;

            /*
            |--------------------------------------------------------------------------
            | 6. Recorrer cada día del periodo
            |--------------------------------------------------------------------------
            */

            for (
                $day = $from;
                $day->lessThanOrEqualTo($until);
                $day = $day->addDay()
            ) {
                $dayStart = $day->startOfDay();
                $dayEnd = $dayStart->addDay();

                /*
                |--------------------------------------------------------------------------
                | 7. Consultar citas existentes de ese día
                |--------------------------------------------------------------------------
                |
                | No se deben generar citas encima de citas reales.
                | Las canceladas no bloquean horarios.
                |
                */

                $existingAppointments = DB::table('appointments')
                    ->where('clinic_id', self::CLINIC_ID)
                    ->whereNotIn('status', [
                        AppointmentStatus::Cancelled->value,
                        'cancelled',
                    ])
                    ->where('start_at', '<', $dayEnd->utc()->format('Y-m-d H:i:sP'))
                    ->where('end_at', '>', $dayStart->utc()->format('Y-m-d H:i:sP'))
                    ->select(
                        'patient_id',
                        'dentist_user_id',
                        'start_at',
                        'end_at'
                    )
                    ->get();

                /*
                |--------------------------------------------------------------------------
                | 8. Registrar horarios ocupados por dentista
                |--------------------------------------------------------------------------
                */

                $dentistSchedules = [];

                foreach ($dentists as $dentist) {
                    $dentistSchedules[$dentist->user_id] = [];
                }

                foreach ($existingAppointments as $appointment) {
                    if ($appointment->dentist_user_id === null) {
                        continue;
                    }

                    $dentistId = (int) $appointment->dentist_user_id;

                    if (!isset($dentistSchedules[$dentistId])) {
                        continue;
                    }

                    $dentistSchedules[$dentistId][] = [
                        'start' => CarbonImmutable::parse(
                            $appointment->start_at,
                            self::TIMEZONE
                        ),
                        'end' => CarbonImmutable::parse(
                            $appointment->end_at,
                            self::TIMEZONE
                        ),
                    ];
                }

                /*
                |--------------------------------------------------------------------------
                | 9. Generar horarios disponibles
                |--------------------------------------------------------------------------
                */

                $slots = [];

                for ($minutes = 8 * 60; $minutes <= 17 * 60; $minutes += 30) {
                    $slots[] = $day->setTime(
                        intdiv($minutes, 60),
                        $minutes % 60
                    );
                }

                shuffle($slots);

                /*
                |--------------------------------------------------------------------------
                | 10. Determinar cantidad de citas por día
                |--------------------------------------------------------------------------
                */

                $isWeekend = $day->isWeekend();

                $dailyCount = $isWeekend
                    ? random_int(2, 4)
                    : random_int(5, 8);

                $dailyCount = min(
                    $dailyCount,
                    count($slots),
                    count($patients)
                );

                /*
                |--------------------------------------------------------------------------
                | 11. Seleccionar pacientes sin repetir en el día
                |--------------------------------------------------------------------------
                */

                $dailyPatients = $patients;

                shuffle($dailyPatients);

                $dailyPatients = array_slice(
                    $dailyPatients,
                    0,
                    $dailyCount
                );

                $createdToday = 0;

                /*
                |--------------------------------------------------------------------------
                | 12. Generar citas
                |--------------------------------------------------------------------------
                */

                foreach ($slots as $startAt) {

                    if ($createdToday >= $dailyCount) {
                        break;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Seleccionar tratamientos
                    |--------------------------------------------------------------------------
                    */

                    $dailyTreatments = $treatments;

                    shuffle($dailyTreatments);

                    $treatmentCount = random_int(
                        1,
                        min(3, count($dailyTreatments))
                    );

                    $dailyTreatments = array_slice(
                        $dailyTreatments,
                        0,
                        $treatmentCount
                    );

                    $totalDuration = 0;
                    $totalAmount = 0;

                    $treatmentNames = [];

                    foreach ($dailyTreatments as $treatment) {
                        $totalDuration += max(
                            15,
                            (int) $treatment->duration
                        );

                        $totalAmount += (float) $treatment->price;

                        $treatmentNames[] = $treatment->name;
                    }

                    $duration = max(
                        30,
                        min(120, $totalDuration)
                    );

                    $endAt = $startAt->addMinutes($duration);

                    /*
                    |--------------------------------------------------------------------------
                    | Buscar dentistas que no tengan traslapes
                    |--------------------------------------------------------------------------
                    */

                    $availableDentists = [];

                    foreach ($dentists as $dentist) {

                        $dentistId = (int) $dentist->user_id;

                        $hasConflict = false;

                        foreach ($dentistSchedules[$dentistId] as $scheduled) {

                            $overlaps =
                                $startAt->lessThan($scheduled['end']) &&
                                $endAt->greaterThan($scheduled['start']);

                            if ($overlaps) {
                                $hasConflict = true;
                                break;
                            }
                        }

                        if (!$hasConflict) {
                            $availableDentists[] = $dentistId;
                        }
                    }

                    // Si ningún dentista está disponible, probar otro horario.
                    if (empty($availableDentists)) {
                        continue;
                    }

                    shuffle($availableDentists);

                    $dentistId = $availableDentists[0];

                    $patientId = (int) $dailyPatients[$createdToday];

                    /*
                    |--------------------------------------------------------------------------
                    | 13. Determinar estado de la cita
                    |--------------------------------------------------------------------------
                    |
                    | Pasado:
                    | 65% Completada
                    | 15% Cancelada
                    | 12% Pendiente
                    |  8% Confirmada
                    |
                    | Futuro:
                    | 60% Pendiente
                    | 40% Confirmada
                    |
                    */

                    if ($endAt->lessThanOrEqualTo($now)) {

                        $randomStatus = random_int(1, 100);

                        if ($randomStatus <= 65) {
                            $status = AppointmentStatus::Completed->value;
                        } elseif ($randomStatus <= 80) {
                            $status = AppointmentStatus::Cancelled->value;
                        } elseif ($randomStatus <= 92) {
                            $status = AppointmentStatus::Pending->value;
                        } else {
                            $status = AppointmentStatus::Confirmed->value;
                        }

                    } else {

                        $status = random_int(1, 100) <= 60
                            ? AppointmentStatus::Pending->value
                            : AppointmentStatus::Confirmed->value;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | 14. Fecha de creación
                    |--------------------------------------------------------------------------
                    */

                    $createdAt = $startAt->subDays(
                        random_int(1, 20)
                    );

                    if ($createdAt->greaterThan($now)) {
                        $createdAt = $now;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | 15. Crear cita
                    |--------------------------------------------------------------------------
                    */

                    $appointmentId = DB::table('appointments')->insertGetId([
                        'clinic_id' => self::CLINIC_ID,
                        'patient_id' => $patientId,
                        'dentist_user_id' => $dentistId,
                        'title' => implode(', ', $treatmentNames),
                        'start_at' => $startAt->utc()->format('Y-m-d H:i:sP'),
                        'end_at' => $endAt->utc()->format('Y-m-d H:i:sP'),
                        'status' => $status,
                        'notes' => self::MARKER . ' Cita de demostración',
                        'created_at' => $createdAt->utc()->format('Y-m-d H:i:s'),
                        'updated_at' => $createdAt->utc()->format('Y-m-d H:i:s'),
                    ]);

                    /*
                    |--------------------------------------------------------------------------
                    | 16. Registrar horario ocupado del dentista
                    |--------------------------------------------------------------------------
                    */

                    $dentistSchedules[$dentistId][] = [
                        'start' => $startAt,
                        'end' => $endAt,
                    ];

                    /*
                    |--------------------------------------------------------------------------
                    | 17. Crear conceptos de la cita
                    |--------------------------------------------------------------------------
                    */

                    $items = [];

                    foreach ($dailyTreatments as $treatment) {

                        $price = (float) $treatment->price;

                        $items[] = [
                            'appointment_id' => $appointmentId,
                            'type' => 'treatment',
                            'treatment_id' => $treatment->id,
                            'product_id' => null,
                            'description' => $treatment->name,
                            'quantity' => 1,
                            'unit_price' => $price,
                            'total' => $price,
                            'created_at' => $createdAt->utc()->format('Y-m-d H:i:s'),
                            'updated_at' => $createdAt->utc()->format('Y-m-d H:i:s'),
                        ];
                    }

                    DB::table('appointment_items')->insert($items);

                    /*
                    |--------------------------------------------------------------------------
                    | 18. Generar pagos solamente para citas completadas
                    |--------------------------------------------------------------------------
                    */

                    if (
                        $status === AppointmentStatus::Completed->value &&
                        $totalAmount > 0
                    ) {

                        $paymentChance = random_int(1, 100);

                        if ($paymentChance <= 70) {
                            $paymentAmount = $totalAmount;
                        } elseif ($paymentChance <= 95) {
                            $paymentAmount = round(
                                $totalAmount * (random_int(20, 80) / 100),
                                2
                            );
                        } else {
                            $paymentAmount = 0;
                        }

                        if ($paymentAmount > 0) {

                            $paymentMethods = [
                                'cash',
                                'card',
                                'transfer',
                            ];

                            $paymentMethod = $paymentMethods[
                                array_rand($paymentMethods)
                            ];

                            $paidAt = $endAt->addMinutes(
                                random_int(5, 180)
                            );

                            if ($paidAt->greaterThan($now)) {
                                $paidAt = $now;
                            }

                            DB::table('payments')->insert([
                                'clinic_id' => self::CLINIC_ID,
                                'appointment_id' => $appointmentId,
                                'patient_id' => $patientId,
                                'received_by' => $dentistId,
                                'amount' => $paymentAmount,
                                'payment_method' => $paymentMethod,
                                'status' => 'completed',
                                'paid_at' => $paidAt->utc()->format('Y-m-d H:i:sP'),
                                'idempotency_key' => (string) Str::uuid(),
                                'payment_group_id' => (string) Str::uuid(),
                                'created_at' => $paidAt->utc()->format('Y-m-d H:i:s'),
                                'updated_at' => $paidAt->utc()->format('Y-m-d H:i:s'),
                            ]);
                        }
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | 19. Actualizar contadores
                    |--------------------------------------------------------------------------
                    */

                    $statusCounts[$status]++;

                    $generatedTotal++;
                    $createdToday++;
                }
            }

            /*
            |--------------------------------------------------------------------------
            | 20. Mostrar resumen
            |--------------------------------------------------------------------------
            */

            $this->command?->newLine();

            $this->command?->info('Citas de demostración generadas correctamente.');

            $this->command?->table(
                ['Estado', 'Cantidad'],
                [
                    ['Pendiente', $statusCounts[AppointmentStatus::Pending->value]],
                    ['Confirmada', $statusCounts[AppointmentStatus::Confirmed->value]],
                    ['Completada', $statusCounts[AppointmentStatus::Completed->value]],
                    ['Cancelada', $statusCounts[AppointmentStatus::Cancelled->value]],
                    ['TOTAL', $generatedTotal],
                ]
            );

            $this->command?->info(
                'Periodo: ' .
                $from->format('d/m/Y') .
                ' al ' .
                $until->format('d/m/Y')
            );
        });
    }
}