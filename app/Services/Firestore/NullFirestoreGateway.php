<?php

namespace App\Services\Firestore;

/**
 * Gateway inactivo: se usa cuando Firebase no está configurado o está
 * desactivado por variable de entorno. La aplicación sigue funcionando solo
 * con SQL.
 */
class NullFirestoreGateway implements FirestoreGateway
{
    public function driver(): string
    {
        return 'null';
    }

    public function enabled(): bool
    {
        return false;
    }

    public function project(): ?string
    {
        return null;
    }

    public function set(string $collection, string $documentId, array $data): bool
    {
        return false;
    }

    public function setMany(array $documents): bool
    {
        return false;
    }

    public function update(string $collection, string $documentId, array $data): bool
    {
        return false;
    }

    public function updateMany(array $documents): bool
    {
        return false;
    }

    public function get(string $collection, string $documentId): ?array
    {
        return null;
    }

    public function delete(string $collection, string $documentId): bool
    {
        return false;
    }

    public function lastError(): ?string
    {
        return null;
    }
}
