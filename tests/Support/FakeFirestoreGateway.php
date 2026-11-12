<?php

namespace Tests\Support;

use App\Services\Firestore\FirestoreGateway;

/**
 * Doble de prueba del espejo NoSQL: guarda los documentos en memoria para
 * poder afirmar qué se sincronizó sin tocar Firebase.
 */
class FakeFirestoreGateway implements FirestoreGateway
{
    /**
     * @var array<string, array<string, array<string, mixed>>>
     */
    public array $documents = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $calls = [];

    public bool $enabled = true;

    public ?string $failure = null;

    public function driver(): string
    {
        return 'fake';
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function project(): ?string
    {
        return 'test-project';
    }

    public function set(string $collection, string $documentId, array $data): bool
    {
        if (! $this->enabled) {
            return false;
        }

        $this->calls[] = ['set', $collection, $documentId];
        $this->documents[$collection][$documentId] = $data;

        return true;
    }

    public function setMany(array $documents): bool
    {
        if (! $this->enabled) {
            return false;
        }

        $this->calls[] = ['setMany', count($documents)];

        foreach ($documents as $document) {
            $this->documents[$document['collection']][$document['id']] = $document['data'];
        }

        return true;
    }

    public function update(string $collection, string $documentId, array $data): bool
    {
        if (! $this->enabled) {
            return false;
        }

        $this->calls[] = ['update', $collection, $documentId];
        $this->documents[$collection][$documentId] = array_merge(
            $this->documents[$collection][$documentId] ?? [],
            $data
        );

        return true;
    }

    public function updateMany(array $documents): bool
    {
        if (! $this->enabled) {
            return false;
        }

        $this->calls[] = ['updateMany', count($documents)];

        foreach ($documents as $document) {
            $this->documents[$document['collection']][$document['id']] = array_merge(
                $this->documents[$document['collection']][$document['id']] ?? [],
                $document['data']
            );
        }

        return true;
    }

    public function get(string $collection, string $documentId): ?array
    {
        return $this->documents[$collection][$documentId] ?? null;
    }

    public function delete(string $collection, string $documentId): bool
    {
        unset($this->documents[$collection][$documentId]);

        return true;
    }

    public function lastError(): ?string
    {
        return $this->failure;
    }

    public function has(string $collection, string $documentId): bool
    {
        return isset($this->documents[$collection][$documentId]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function collection(string $collection): array
    {
        return $this->documents[$collection] ?? [];
    }
}
