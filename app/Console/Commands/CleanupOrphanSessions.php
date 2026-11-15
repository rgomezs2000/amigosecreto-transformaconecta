<?php

namespace App\Console\Commands;

use App\Services\SessionSecurity;
use Illuminate\Console\Command;

class CleanupOrphanSessions extends Command
{
    protected $signature = 'sessions:cleanup
                            {--minutes= : Minutos de inactividad para considerar huérfana una sesión}';

    protected $description = 'Elimina sesiones huérfanas y limpia avisos antiguos';

    public function handle(): int
    {
        $minutes = max(1, (int) ($this->option('minutes') ?: config('session.orphan_minutes', 1)));
        $result = SessionSecurity::cleanupOrphans($minutes);

        $this->info("Sesiones huérfanas eliminadas: {$result['deleted']}. Sesiones activas limpiadas: {$result['cleared']}.");

        return self::SUCCESS;
    }
}
