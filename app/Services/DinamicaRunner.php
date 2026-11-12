<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Lanza el comando `dinamica:reiniciar`.
 *
 * Normalmente lo ejecuta en un proceso aparte para que el panel pueda mostrar
 * el avance en vivo. Si el servidor no permite abrir procesos, el comando se
 * ejecuta en la misma petición y el panel ve el resultado final de inmediato.
 */
class DinamicaRunner
{
    public function __construct(private readonly DinamicaProgress $progress) {}

    public function start(string $run): void
    {
        $this->progress->start($run);

        if ($this->startInBackground($run)) {
            return;
        }

        $this->runInline($run);
    }

    private function startInBackground(string $run): bool
    {
        if (! (bool) config('dinamica.async', true)) {
            return false;
        }

        $binary = $this->phpBinary();

        if ($binary === null) {
            return false;
        }

        try {
            $process = new Process([
                $binary,
                base_path('artisan'),
                'dinamica:reiniciar',
                '--run='.$run,
                '--force',
            ], base_path());

            $process->setTimeout(null);
            $process->disableOutput();
            $process->start();

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    private function runInline(string $run): void
    {
        try {
            Artisan::call('dinamica:reiniciar', ['--run' => $run, '--force' => true]);
        } catch (Throwable $exception) {
            report($exception);

            $this->progress->finish($run, false, 'El restablecimiento falló.', $exception->getMessage());
        }
    }

    /**
     * Ruta al ejecutable de PHP.
     *
     * Con Apache, PHP_BINARY puede apuntar al servidor y no a php.exe, así que
     * se prueban las rutas habituales antes de darse por vencido.
     */
    private function phpBinary(): ?string
    {
        $configured = config('dinamica.php_binary');

        $candidates = array_filter([
            is_string($configured) ? $configured : null,
            PHP_BINARY,
            PHP_BINDIR.DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php'),
            PHP_BINDIR.DIRECTORY_SEPARATOR.'php',
        ]);

        foreach ($candidates as $candidate) {
            $name = strtolower(basename($candidate));

            if (! str_starts_with($name, 'php')) {
                continue;
            }

            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
