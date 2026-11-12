<?php

namespace App\Services\Firestore;

use Google\Cloud\Firestore\FirestoreClient;
use Kreait\Laravel\Firebase\Facades\Firebase;
use Throwable;

/**
 * Transporte oficial (google/cloud-firestore + Firebase::firestore()).
 *
 * Sólo está disponible si el servidor tiene la extensión gRPC y el paquete
 * google/cloud-firestore instalado. Se activa con
 * FIREBASE_FIRESTORE_DRIVER=sdk; el valor por defecto (rest) no lo usa.
 */
class SdkFirestoreGateway implements FirestoreGateway
{
    private ?FirestoreClient $client = null;

    private bool $failed = false;

    private ?string $lastError = null;

    public function __construct(private readonly ?string $projectId = null) {}

    public function driver(): string
    {
        return 'sdk';
    }

    public function enabled(): bool
    {
        return class_exists(FirestoreClient::class) && $this->client() !== null;
    }

    public function project(): ?string
    {
        return $this->projectId;
    }

    public function set(string $collection, string $documentId, array $data): bool
    {
        $client = $this->client();

        if ($client === null) {
            return false;
        }

        try {
            $client->collection($collection)->document($documentId)->set($data);

            return true;
        } catch (Throwable $exception) {
            $this->fail($exception);

            return false;
        }
    }

    public function setMany(array $documents): bool
    {
        $client = $this->client();

        if ($client === null) {
            return false;
        }

        try {
            $batch = $client->batch();

            foreach ($documents as $document) {
                $batch->set(
                    $client->collection($document['collection'])->document($document['id']),
                    $document['data']
                );
            }

            $batch->commit();

            return true;
        } catch (Throwable $exception) {
            $this->fail($exception);

            return false;
        }
    }

    public function update(string $collection, string $documentId, array $data): bool
    {
        $client = $this->client();

        if ($client === null) {
            return false;
        }

        try {
            $client->collection($collection)->document($documentId)->set($data, ['merge' => true]);

            return true;
        } catch (Throwable $exception) {
            $this->fail($exception);

            return false;
        }
    }

    public function updateMany(array $documents): bool
    {
        $client = $this->client();

        if ($client === null) {
            return false;
        }

        try {
            $batch = $client->batch();

            foreach ($documents as $document) {
                if ($document['data'] === []) {
                    continue;
                }

                $batch->set(
                    $client->collection($document['collection'])->document($document['id']),
                    $document['data'],
                    ['merge' => true]
                );
            }

            $batch->commit();

            return true;
        } catch (Throwable $exception) {
            $this->fail($exception);

            return false;
        }
    }

    public function get(string $collection, string $documentId): ?array
    {
        $client = $this->client();

        if ($client === null) {
            return null;
        }

        try {
            $snapshot = $client->collection($collection)->document($documentId)->snapshot();

            return $snapshot->exists() ? $snapshot->data() : null;
        } catch (Throwable $exception) {
            $this->fail($exception);

            return null;
        }
    }

    public function delete(string $collection, string $documentId): bool
    {
        $client = $this->client();

        if ($client === null) {
            return false;
        }

        try {
            $client->collection($collection)->document($documentId)->delete();

            return true;
        } catch (Throwable $exception) {
            $this->fail($exception);

            return false;
        }
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    private function client(): ?FirestoreClient
    {
        if ($this->client !== null || $this->failed) {
            return $this->client;
        }

        try {
            $this->client = Firebase::firestore()->database();
        } catch (Throwable $exception) {
            $this->failed = true;
            $this->lastError = $exception->getMessage();
        }

        return $this->client;
    }

    private function fail(Throwable $exception): void
    {
        $this->lastError = $exception->getMessage();
        $this->failed = true;
        $this->client = null;
    }
}
