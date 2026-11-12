<?php

namespace App\Console\Commands;

use App\Services\Firestore\FirestoreService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class FirebaseCheck extends Command
{
    protected $signature = 'firebase:check {--write : Prueba también una escritura y la borra al instante}';

    protected $description = 'Comprueba la conexión del espejo NoSQL (Firestore)';

    public function handle(FirestoreService $firestore): int
    {
        $status = $firestore->status();

        $this->table(['Clave', 'Valor'], [
            ['Transporte', $status['driver']],
            ['Proyecto', $status['project'] ?? '—'],
            ['Habilitado', $status['enabled'] ? 'sí' : 'no'],
            ['Último error', $status['last_error'] ?? '—'],
        ]);

        if (! $status['enabled']) {
            $this->warn('Firestore está desactivado. Revisa FIREBASE_CREDENTIALS y FIREBASE_FIRESTORE_DRIVER.');

            return self::FAILURE;
        }

        if (! $this->option('write')) {
            $this->info('Firestore está configurado. Usa --write para probar una escritura.');

            return self::SUCCESS;
        }

        $id = 'diagnostico-'.Str::uuid();

        $written = $firestore->gateway()->set(FirestoreService::EMAILS, $id, [
            'diagnostico' => true,
            'created_at' => now()->toIso8601String(),
        ]);

        if (! $written) {
            $this->error('No se pudo escribir en Firestore: '.($firestore->gateway()->lastError() ?? 'error desconocido'));

            return self::FAILURE;
        }

        $document = $firestore->gateway()->get(FirestoreService::EMAILS, $id);
        $firestore->gateway()->delete(FirestoreService::EMAILS, $id);

        $this->info('Escritura, lectura y borrado correctos.');

        if ($document !== null) {
            $this->line('Documento leído: '.json_encode($document, JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
