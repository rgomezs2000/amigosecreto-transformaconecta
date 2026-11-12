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
            self::Registration => 'Inscripción',
            self::DrawEnabled => 'Sorteo habilitado',
            self::Drawn => 'Sorteado',
            self::Closed => 'Cerrado',
            self::Revealed => 'Revelado',
            self::Suspended => 'Suspendido',
        };
    }

    /** El administrador todavía puede inscribir o quitar participantes. */
    public function allowsRegistration(): bool
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

    /** El administrador puede disparar la revelación final. */
    public function allowsReveal(): bool
    {
        return $this === self::Closed;
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Closed, self::Revealed], true);
    }
}
