<?php

declare(strict_types=1);

namespace App\Enums;

enum AppointmentStatus: string
{
    case Pending = 'Pendiente';
    case Confirmed = 'Confirmada';
    case Completed = 'Completada';
    case Cancelled = 'Cancelada';

    public function isClosed(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }
}