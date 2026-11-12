<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Estado y bitácora de una ejecución de «dinamica:reiniciar».
 *
 * Cada ejecución escribe un archivo JSON con su avance (paso, total, estado y
 * las últimas líneas) en config('dinamica.path'). Esa misma información va al
 * canal de log configurado, que es la bitácora permanente del proceso.
 *
 * El panel lee el archivo para mover la barra de progreso mientras el comando
 * corre en un proceso aparte.
 */
class DinamicaProgress
{
    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /** Líneas que se conservan en el archivo de estado. */
    private const MAX_LINES = 400;

    public function directory(): string
    {
        return (string) config('dinamica.path', storage_path('app/dinamica'));
    }

    public function path(string $run): string
    {
        return rtrim($this->directory(), '\\/').DIRECTORY_SEPARATOR.$run.'.json';
    }

    /**
     * Identificador válido: solo letras, números y guiones. Evita que el
     * nombre del archivo salga de la carpeta de ejecuciones.
     */
    public function isValidRun(string $run): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9\-]{8,64}$/', $run);
    }

    /**
     * Deja la ejecución en marcha y limpia archivos viejos.
     *
     * @return array<string, mixed>
     */
    public function start(string $run): array
    {
        $this->ensureDirectory();
        $this->prune();

        return $this->write($run, [
            'run' => $run,
            'status' => self::RUNNING,
            'step' => 0,
            'total' => 0,
            'percent' => 0,
            'message' => 'Preparando el proceso…',
            'lines' => [],
            'error' => null,
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
        ]);
    }

    /**
     * Registra un paso terminado.
     *
     * @return array<string, mixed>
     */
    public function step(string $run, int $step, int $total, string $message, int $rows = 0): array
    {
        $state = $this->read($run) ?? $this->start($run);

        $state['step'] = $step;
        $state['total'] = $total;
        $state['percent'] = $total > 0 ? (int) floor(($step / $total) * 100) : 0;
        $state['message'] = $message;

        $this->push($state, 'info', $step.'/'.$total.' · '.$message.' ('.$rows.' registro(s))');

        return $this->write($run, $state);
    }

    public function note(string $run, string $text, string $level = 'info'): void
    {
        $state = $this->read($run) ?? $this->start($run);

        $this->push($state, $level, $text);

        $this->write($run, $state);
    }

    /**
     * Cierra la ejecución.
     *
     * @return array<string, mixed>
     */
    public function finish(string $run, bool $ok, string $message, ?string $error = null): array
    {
        $state = $this->read($run) ?? $this->start($run);

        $state['status'] = $ok ? self::DONE : self::FAILED;
        $state['message'] = $message;
        $state['error'] = $error;
        $state['finished_at'] = now()->toIso8601String();
        $state['percent'] = $ok ? 100 : (int) ($state['percent'] ?? 0);

        $this->push($state, $ok ? 'info' : 'error', $message.($error !== null ? ' · '.$error : ''));

        return $this->write($run, $state);
    }

    /**
     * El usuario canceló antes de empezar (solo consola).
     *
     * @return array<string, mixed>
     */
    public function cancel(string $run, string $message = 'Proceso cancelado.'): array
    {
        $state = $this->read($run) ?? $this->start($run);

        $state['status'] = self::CANCELLED;
        $state['message'] = $message;
        $state['finished_at'] = now()->toIso8601String();

        $this->push($state, 'warning', $message);

        return $this->write($run, $state);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(string $run): ?array
    {
        if (! $this->isValidRun($run)) {
            return null;
        }

        $path = $this->path($run);

        if (! is_file($path)) {
            return null;
        }

        // El comando escribe mientras el panel lee: se reintenta si el
        // archivo se leyó a medias.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $raw = @file_get_contents($path);

            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);

                if (is_array($decoded)) {
                    return $decoded;
                }
            }

            usleep(15000);
        }

        return null;
    }

    /**
     * Última ejecución registrada, en curso o terminada.
     *
     * @return array<string, mixed>|null
     */
    public function latest(): ?array
    {
        $files = glob(rtrim($this->directory(), '\\/').DIRECTORY_SEPARATOR.'*.json') ?: [];

        if ($files === []) {
            return null;
        }

        usort($files, fn (string $a, string $b) => filemtime($b) <=> filemtime($a));

        return $this->read(basename($files[0], '.json'));
    }

    /**
     * Ejecución en curso que todavía se considera viva.
     *
     * @return array<string, mixed>|null
     */
    public function running(): ?array
    {
        $state = $this->latest();

        if ($state === null || ($state['status'] ?? null) !== self::RUNNING) {
            return null;
        }

        $startedAt = isset($state['started_at']) ? Carbon::parse((string) $state['started_at']) : null;
        $limit = max(1, (int) config('dinamica.stale_after', 15));

        if ($startedAt !== null && $startedAt->diffInMinutes(now()) > $limit) {
            return null;
        }

        return $state;
    }

    /**
     * @return array<string, mixed>
     */
    private function write(string $run, array $state): array
    {
        $this->ensureDirectory();

        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if ($json !== false) {
            @file_put_contents($this->path($run), $json, LOCK_EX);
        }

        return $state;
    }

    /**
     * Agrega una línea a la bitácora de la ejecución y al log de Laravel.
     *
     * @param  array<string, mixed>  $state
     */
    private function push(array &$state, string $level, string $text): void
    {
        $state['lines'] = is_array($state['lines'] ?? null) ? $state['lines'] : [];
        $state['lines'][] = [
            'at' => now()->format('H:i:s'),
            'level' => $level,
            'text' => $text,
        ];

        $state['lines'] = array_slice($state['lines'], -self::MAX_LINES);

        $channel = (string) config('dinamica.log', 'dinamica');

        Log::channel($channel)->{$level}($text, ['run' => $state['run'] ?? null]);
    }

    private function ensureDirectory(): void
    {
        $directory = $this->directory();

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0775, true);
        }
    }

    /**
     * Borra los archivos de ejecuciones antiguas.
     */
    private function prune(): void
    {
        $files = glob(rtrim($this->directory(), '\\/').DIRECTORY_SEPARATOR.'*.json') ?: [];
        $limit = now()->subDays(max(1, (int) config('dinamica.keep_days', 7)))->getTimestamp();

        foreach ($files as $file) {
            if (filemtime($file) < $limit) {
                @unlink($file);
            }
        }

        // Nunca más de 20 ejecuciones guardadas.
        $files = glob(rtrim($this->directory(), '\\/').DIRECTORY_SEPARATOR.'*.json') ?: [];

        if (count($files) > 20) {
            usort($files, fn (string $a, string $b) => filemtime($a) <=> filemtime($b));

            foreach (array_slice($files, 0, count($files) - 20) as $file) {
                @unlink($file);
            }
        }
    }
}
