<?php

namespace App\Enums;

enum ParticipantStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';
    case Expelled = 'expelled';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En espera',
            self::Active => 'Activo',
            self::Declined => 'Rechazado',
            self::Withdrawn => 'Retirado',
            self::Expelled => 'Expulsado',
            self::Dismissed => 'Descartado',
        };
    }

    /**
     * Clase de la etiqueta de estado en las tablas.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Active => 'status-badge--active',
            self::Pending => 'status-badge--pending',
            self::Declined, self::Withdrawn, self::Expelled, self::Dismissed => 'status-badge--inactive',
        };
    }

    /** Solo los activos entran al sorteo y a la dinámica. */
    public function countsForDraw(): bool
    {
        return $this === self::Active;
    }

    /** La invitación todavía puede aceptarse o rechazarse. */
    public function canRespondInvitation(): bool
    {
        return $this === self::Pending;
    }

    /** El usuario forma parte de la dinámica. */
    public function isParticipating(): bool
    {
        return $this === self::Active;
    }

    /** Estados que ya no cuentan como «en espera» para iniciar el evento. */
    public function blocksEventStart(): bool
    {
        return $this === self::Pending;
    }

    /** El usuario (o la administración) puede retirarse o expulsarlo. */
    public function canBeRemoved(): bool
    {
        return in_array($this, [self::Active, self::Pending], true);
    }
}
