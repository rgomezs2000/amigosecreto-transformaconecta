<?php

namespace App\Enums;

enum EventStatus: string
{
    case Draft = 'draft';
    case Registration = 'registration';
    case DrawEnabled = 'draw_enabled';
    case Drawn = 'drawn';
    case Closed = 'closed';
    case Revealed = 'revealed';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Registration => 'Unión',
            self::DrawEnabled => 'Sorteo ciego',
            self::Drawn => 'Intercambio de pistas',
            self::Closed => 'Finalizado',
            self::Revealed => 'Gran revelación',
            self::Suspended => 'Suspendido',
        };
    }

    /** El administrador todavía puede inscribir o quitar participantes. */
    public function allowsRegistration(): bool
    {
        return in_array($this, [self::Draft, self::Registration], true);
    }

    /** Se pueden generar y enviar invitaciones personales. */
    public function allowsInvitations(): bool
    {
        return in_array($this, [self::Draft, self::Registration], true);
    }

    /** El módulo de sorteo está habilitado y aún no hay reparto. */
    public function allowsDraw(): bool
    {
        return $this === self::DrawEnabled;
    }

    /** Se pueden registrar obsequios (regalo único y consumibles). */
    public function allowsGifts(): bool
    {
        return $this === self::Drawn;
    }

    /** Se pueden escribir pistas para el amigo secreto. */
    public function allowsClues(): bool
    {
        return $this === self::Drawn;
    }

    /** La gran revelación se abre cuando llega la fecha del intercambio. */
    public function allowsReveal(): bool
    {
        return in_array($this, [self::Drawn, self::Closed, self::Revealed], true);
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Closed, self::Revealed], true);
    }
}
