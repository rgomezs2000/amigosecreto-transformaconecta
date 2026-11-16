<?php

namespace App\Console\Commands;

use App\Services\SessionSecurity;
use Illuminate\Console\Command;

class CleanupOrphanSessions extends Command
{
    protected $signature = 'sessions:cleanup
                            {--minutes= : Minutos de inactividad para considerar huérfana una sesión}';

    protected $description = 'Elimina sesiones huérfanas de usuarios autenticados y limpia avisos antiguos';

    public function handle(): int
    {
        $requested = max(1, (int) ($this->option('minutes') ?: config('session.orphan_minutes', SessionSecurity::MIN_ORPHAN_MINUTES)));
        $minutes = max(SessionSecurity::MIN_ORPHAN_MINUTES, $requested);
        $result = SessionSecurity::cleanupOrphans($minutes);

        $this->info("Sesiones huérfanas eliminadas: {$result['deleted']}. Sesiones activas limpiadas: {$result['cleared']}.");

        if ($minutes > $requested) {
            $this->line("Se aplicó el mínimo de {$minutes} minutos: por debajo de eso el latido del navegador aún puede estar en pausa y la sesión seguiría viva.");
        }

        return self::SUCCESS;
    }
}
