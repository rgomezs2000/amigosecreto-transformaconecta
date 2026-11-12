<?php

namespace App\Services\Firestore;

/**
 * Contrato mínimo del almacén NoSQL (Firestore).
 *
 * Todas las implementaciones deben ser tolerantes a fallos: si Firebase no
 * está disponible devuelven `false` en lugar de lanzar excepciones, para que
 * el flujo principal (SQL) nunca se detenga por culpa del espejo NoSQL.
 */
interface FirestoreGateway
{
    /**
     * Identificador del transporte en uso: rest, sdk o null.
     */
    public function driver(): string;

    /**
     * ¿Hay credenciales y transporte disponibles?
     */
    public function enabled(): bool;

    /**
     * Proyecto de Firebase al que se escribe.
     */
    public function project(): ?string;

    /**
     * Crea o reemplaza un documento completo.
     *
     * @param  array<string, mixed>  $data
     */
    public function set(string $collection, string $documentId, array $data): bool;

    /**
     * Escribe varios documentos en una sola petición.
     *
     * @param  array<int, array{collection:string, id:string, data:array<string, mixed>}>  $documents
     */
    public function setMany(array $documents): bool;

    /**
     * Actualiza solo los campos indicados de un documento existente.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(string $collection, string $documentId, array $data): bool;

    /**
     * Actualiza varios documentos (solo los campos indicados) en una petición.
     *
     * @param  array<int, array{collection:string, id:string, data:array<string, mixed>}>  $documents
     */
    public function updateMany(array $documents): bool;

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $collection, string $documentId): ?array;

    public function delete(string $collection, string $documentId): bool;

    /**
     * Último error registrado, para mostrarlo en el panel de administración.
     */
    public function lastError(): ?string;
}
