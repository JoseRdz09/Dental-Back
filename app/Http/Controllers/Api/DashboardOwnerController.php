<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardOwnerController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | ENDPOINTS
    |--------------------------------------------------------------------------
    */

    /**
     * Clínicas disponibles para el filtro.
     */
    public function clinics(Request $request): JsonResponse
    {
        $clinics = $this->clinicsQuery($request)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'data' => $clinics,
        ]);
    }

    /**
     * Estadísticas correspondientes a una fecha seleccionada.
     *
     * - clinic_id vacío: todas las clínicas de la organización.
     * - date: fecha seleccionada en formato YYYY-MM-DD.
     *
     * El día se calcula POR CLÍNICA en su propia zona horaria.
     */
    public function stats(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'clinic_id' => ['nullable', 'integer', 'min:1'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $clinicId = isset($validated['clinic_id'])
            ? (int) $validated['clinic_id']
            : null;

        $clinics = $this->selectedClinics($request, $clinicId);

        $ranges = $this->buildRanges($clinics, $validated['date']);

        $nowUtc = CarbonImmutable::now()->utc();

        /*
        |--------------------------------------------------------------------------
        | CITAS
        |--------------------------------------------------------------------------
        */

        $appointments = Appointment::query()
            ->toBase()
            ->where(function ($query) use ($ranges) {
                foreach ($ranges as $r) {
                    $query->orWhere(function ($sub) use ($r) {
                        $sub->where('clinic_id', $r['clinic_id'])
                            ->where('start_at', '>=', $r['start']->utc())
                            ->where('start_at', '<', $r['end']->utc());
                    });
                }
            })
            ->selectRaw(
                '
                COUNT(*) FILTER (WHERE status = ?) AS confirmed,
                COUNT(*) FILTER (WHERE status = ?) AS pending,
                COUNT(*) FILTER (WHERE status = ?) AS completed,
                COUNT(*) FILTER (WHERE start_at < ? AND status <> ?) AS elapsed
                ',
                [
                    AppointmentStatus::Confirmed->value,
                    AppointmentStatus::Pending->value,
                    AppointmentStatus::Completed->value,
                    $nowUtc,
                    AppointmentStatus::Cancelled->value,
                ]
            )
            ->first();

        $completed = (int) $appointments->completed;
        $elapsed = (int) $appointments->elapsed;

        $performance = $elapsed > 0
            ? min(100, (int) round(($completed / $elapsed) * 100))
            : 0;

        /*
        |--------------------------------------------------------------------------
        | INGRESOS
        |--------------------------------------------------------------------------
        */

        $income = $this->sumPayments($ranges);

        /*
        |--------------------------------------------------------------------------
        | RESPUESTA
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'data' => [
                'monthlyIncome' => $income,
                'performance' => $performance,
                'confirmedAppointments' => (int) $appointments->confirmed,
                'pendingAppointments' => (int) $appointments->pending,
                'completedAppointments' => $completed,
                'todaySales' => $income,
                'chart' => $this->chart($ranges),
            ],
        ]);
    }

    /**
     * Equipo asignado a las clínicas seleccionadas.
     */
    public function team(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'clinic_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $clinicId = isset($validated['clinic_id'])
            ? (int) $validated['clinic_id']
            : null;

        $clinicIds = $this->selectedClinics($request, $clinicId)
            ->pluck('id')
            ->all();

        $team = DB::table('clinic_user')
            ->join('users', 'users.id', '=', 'clinic_user.user_id')
            ->whereIn('clinic_user.clinic_id', $clinicIds)
            ->orderBy('users.name')
            ->get([
                'users.id',
                'users.name',
                'clinic_user.role',
                'clinic_user.clinic_id',
            ]);

        return response()->json([
            'data' => $team,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | ACCESO A CLÍNICAS
    |--------------------------------------------------------------------------
    */

    /**
     * Clínicas activas pertenecientes a las organizaciones del usuario.
     */
    private function clinicsQuery(Request $request): Builder
    {
        $userId = (int) $request->user()->id;

        return Clinic::query()
            ->where('clinics.status', 'active')
            ->whereIn('clinics.organization_id', function ($sub) use ($userId) {
                $sub->select('organization_id')
                    ->from('organization_user')
                    ->where('user_id', $userId);
            });
    }

    /**
     * Obtiene una clínica o todas las clínicas accesibles.
     */
    private function selectedClinics(
        Request $request,
        ?int $clinicId
    ): Collection {
        $clinics = $this->clinicsQuery($request)
            ->when(
                $clinicId,
                fn ($query) => $query->whereKey($clinicId)
            )
            ->get([
                'id',
                'timezone',
            ]);

        abort_if(
            $clinics->isEmpty(),
            403,
            'No tienes acceso a esta clínica.'
        );

        return $clinics;
    }

    /*
    |--------------------------------------------------------------------------
    | CÁLCULOS
    |--------------------------------------------------------------------------
    */

    /**
     * Construye un rango [start, end) por clínica, usando la zona horaria
     * de cada una. Así "el día seleccionado" es el mismo día local en
     * todas las clínicas.
     */
    private function buildRanges(Collection $clinics, string $date): Collection
    {
        $default = config('app.timezone', 'UTC');

        return $clinics->map(function ($clinic) use ($date, $default) {
            $tz = $clinic->timezone ?: $default;

            $start = CarbonImmutable::createFromFormat('!Y-m-d', $date, $tz)
                ->startOfDay();

            return [
                'clinic_id' => (int) $clinic->id,
                'timezone' => $tz,
                'start' => $start,
                'end' => $start->addDay(),
            ];
        })->values();
    }

    /**
     * Convierte un importe a centavos para evitar errores de precisión.
     */
    private function toCents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /**
     * Suma los pagos completados dentro del día seleccionado,
     * respetando la zona horaria de cada clínica.
     */
    private function sumPayments(Collection $ranges): float
    {
        $cents = 0;

        foreach ($ranges as $r) {
            $sum = Payment::query()
                ->where('clinic_id', $r['clinic_id'])
                ->where('status', 'completed')
                ->where('paid_at', '>=', $r['start']->utc())
                ->where('paid_at', '<', $r['end']->utc())
                ->sum('amount');

            $cents += $this->toCents($sum);
        }

        return $cents / 100;
    }

    /**
     * Ingresos del día seleccionado agrupados en 7 intervalos de dos horas:
     * 7-9, 9-11, 11-13, 13-15, 15-17, 17-19 y 19-21 (hora local de cada clínica).
     */
    private function chart(Collection $ranges): array
    {
        // Buckets: 7, 9, 11, 13, 15, 17 y 19.
        $hours = range(7, 19, 2);

        $amounts = array_fill_keys($hours, 0);

        $localHour = 'EXTRACT(HOUR FROM (paid_at AT TIME ZONE ?::text))';

        foreach ($ranges as $r) {
            $rows = Payment::query()
                ->where('clinic_id', $r['clinic_id'])
                ->where('status', 'completed')
                ->where('paid_at', '>=', $r['start']->utc())
                ->where('paid_at', '<', $r['end']->utc())
                // Horas 7 a 20 = de 7:00 AM a 8:59 PM
                ->whereRaw("{$localHour} BETWEEN 7 AND 20", [$r['timezone']])
                ->selectRaw(
                    "(FLOOR(({$localHour} - 7) / 2) * 2 + 7)::int AS bucket,
                    SUM(amount) AS total",
                    [$r['timezone']]
                )
                ->groupBy('bucket')
                ->get();

            foreach ($rows as $row) {
                $hour = (int) $row->bucket;

                if (array_key_exists($hour, $amounts)) {
                    $amounts[$hour] += $this->toCents($row->total);
                }
            }
        }

        return array_map(
            fn (int $hour) => [
                'label' => sprintf('%02dh', $hour), // "07h" ... "19h"
                'value' => $amounts[$hour] / 100,
            ],
            $hours
        );
    }
}
