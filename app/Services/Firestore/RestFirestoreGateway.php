<?php

namespace App\Services\Firestore;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Cliente Firestore por REST.
 *
 * Se usa cuando la extensión gRPC de PHP no está instalada (caso de XAMPP):
 * google/cloud-firestore exige ext-grpc, pero la API REST de Firestore sí
 * funciona con google/auth, que ya es dependencia del proyecto.
 */
class RestFirestoreGateway implements FirestoreGateway
{
    private const SCOPE = 'https://www.googleapis.com/auth/datastore';

    private const MAX_BATCH = 400;

    private ?string $accessToken = null;

    private int $accessTokenExpiresAt = 0;

    private ?string $lastError = null;

    public function __construct(
        private readonly string $projectId,
        private readonly string $database,
        private readonly string $credentialsPath,
        private readonly int $timeout = 6,
        private readonly int $connectTimeout = 2,
    ) {}

    public function driver(): string
    {
        return 'rest';
    }

    public function enabled(): bool
    {
        return $this->projectId !== ''
            && $this->credentialsPath !== ''
            && is_file($this->credentialsPath)
            && is_readable($this->credentialsPath);
    }

    public function project(): ?string
    {
        return $this->projectId !== '' ? $this->projectId : null;
    }

    public function set(string $collection, string $documentId, array $data): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $response = $this->send('PATCH', $this->documentUrl($collection, $documentId), [
            'fields' => $this->encodeFields($data),
        ]);

        return $response !== null && $response->successful();
    }

    public function setMany(array $documents): bool
    {
        if (! $this->enabled() || $documents === []) {
            return false;
        }

        $ok = true;

        foreach (array_chunk($documents, self::MAX_BATCH) as $chunk) {
            $writes = [];

            foreach ($chunk as $document) {
                $writes[] = [
                    'update' => [
                        'name' => $this->resourceName($document['collection'], $document['id']),
                        'fields' => $this->encodeFields($document['data']),
                    ],
                ];
            }

            $ok = $ok && $this->batchWrite($writes);
        }

        return $ok;
    }

    public function update(string $collection, string $documentId, array $data): bool
    {
        if (! $this->enabled() || $data === []) {
            return false;
        }

        $mask = [];

        foreach (array_keys($data) as $field) {
            $mask[] = 'updateMask.fieldPaths='.rawurlencode((string) $field);
        }

        $response = $this->send(
            'PATCH',
            $this->documentUrl($collection, $documentId).'?'.implode('&', $mask),
            ['fields' => $this->encodeFields($data)]
        );

        return $response !== null && $response->successful();
    }

    public function updateMany(array $documents): bool
    {
        if (! $this->enabled() || $documents === []) {
            return false;
        }

        $ok = true;

        foreach (array_chunk($documents, self::MAX_BATCH) as $chunk) {
            $writes = [];

            foreach ($chunk as $document) {
                if ($document['data'] === []) {
                    continue;
                }

                $writes[] = [
                    'update' => [
                        'name' => $this->resourceName($document['collection'], $document['id']),
                        'fields' => $this->encodeFields($document['data']),
                    ],
                    'updateMask' => [
                        'fieldPaths' => array_map('strval', array_keys($document['data'])),
                    ],
                ];
            }

            if ($writes !== []) {
                $ok = $ok && $this->batchWrite($writes);
            }
        }

        return $ok;
    }

    public function get(string $collection, string $documentId): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $response = $this->send('GET', $this->documentUrl($collection, $documentId));

        if ($response === null || ! $response->successful()) {
            return null;
        }

        $payload = $response->json();

        if (! is_array($payload) || ! isset($payload['fields']) || ! is_array($payload['fields'])) {
            return [];
        }

        return $this->decodeFields($payload['fields']);
    }

    public function delete(string $collection, string $documentId): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $response = $this->send('DELETE', $this->documentUrl($collection, $documentId));

        return $response !== null && ($response->successful() || $response->status() === 404);
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    private function endpoint(): string
    {
        return sprintf(
            'https://firestore.googleapis.com/v1/projects/%s/databases/%s/documents',
            rawurlencode($this->projectId),
            rawurlencode($this->database)
        );
    }

    private function documentUrl(string $collection, string $documentId): string
    {
        return $this->endpoint().'/'.rawurlencode($this->documentPart($collection)).'/'.rawurlencode($this->documentPart($documentId));
    }

    private function resourceName(string $collection, string $documentId): string
    {
        return sprintf(
            'projects/%s/databases/%s/documents/%s/%s',
            $this->projectId,
            $this->database,
            $this->documentPart($collection),
            $this->documentPart($documentId)
        );
    }

    /**
     * Los identificadores de documento no pueden contener barras.
     */
    private function documentPart(string $value): string
    {
        return trim(str_replace(['/', '\\'], '_', $value));
    }

    /**
     * @param  array<int, array<string, mixed>>  $writes
     */
    private function batchWrite(array $writes): bool
    {
        if ($writes === []) {
            return true;
        }

        $response = $this->send('POST', $this->endpoint().':batchWrite', ['writes' => $writes]);

        return $response !== null && $response->successful();
    }

    private function send(string $method, string $url, ?array $payload = null): ?Response
    {
        $token = $this->token();

        if ($token === null) {
            return null;
        }

        $http = Http::withToken($token)
            ->acceptJson()
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout);

        try {
            $response = match ($method) {
                'GET' => $http->get($url),
                'PATCH' => $http->patch($url, $payload ?? []),
                'POST' => $http->post($url, $payload ?? []),
                'DELETE' => $http->delete($url),
                default => throw new RuntimeException('Método HTTP no soportado: '.$method),
            };
        } catch (Throwable $exception) {
            $this->fail($method.' '.$url.': '.$exception->getMessage());

            return null;
        }

        if (! $response->successful() && $response->status() !== 404) {
            $this->fail($method.' '.$url.' → '.$response->status().' '.$this->errorMessage($response));
        } elseif ($response->successful()) {
            $this->lastError = null;
        }

        return $response;
    }

    private function token(): ?string
    {
        if ($this->accessToken !== null && time() < $this->accessTokenExpiresAt) {
            return $this->accessToken;
        }

        $cacheKey = 'firestore_access_token_'.sha1($this->credentialsPath.'|'.$this->projectId);

        try {
            $cached = Cache::get($cacheKey);

            if (is_string($cached) && $cached !== '') {
                $this->accessToken = $cached;
                $this->accessTokenExpiresAt = time() + 120;

                return $cached;
            }

            $credentials = new ServiceAccountCredentials(self::SCOPE, $this->credentialsPath);
            $result = $credentials->fetchAuthToken();
            $token = is_array($result) ? ($result['access_token'] ?? null) : null;

            if (! is_string($token) || $token === '') {
                throw new RuntimeException('La cuenta de servicio no devolvió un token de acceso.');
            }

            $expiresIn = max(300, (int) ($result['expires_in'] ?? 3600) - 120);

            $this->accessToken = $token;
            $this->accessTokenExpiresAt = time() + $expiresIn;
            Cache::put($cacheKey, $token, $expiresIn);

            return $token;
        } catch (Throwable $exception) {
            $this->fail('token: '.$exception->getMessage());

            return null;
        }
    }

    private function errorMessage(Response $response): string
    {
        $payload = $response->json();

        if (is_array($payload) && isset($payload['error']['message']) && is_string($payload['error']['message'])) {
            return mb_substr($payload['error']['message'], 0, 300);
        }

        return mb_substr(trim(strip_tags((string) $response->body())), 0, 300);
    }

    private function fail(string $message): void
    {
        $this->lastError = $message;
        $this->accessToken = null;
        $this->accessTokenExpiresAt = 0;

        Log::warning('Firestore: '.$message);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, array<string, mixed>>
     */
    public function encodeFields(array $fields): array
    {
        $encoded = [];

        foreach ($fields as $key => $value) {
            $encoded[(string) $key] = $this->encodeValue($value);
        }

        return $encoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function encodeValue(mixed $value): array
    {
        if ($value === null) {
            return ['nullValue' => null];
        }

        if (is_bool($value)) {
            return ['booleanValue' => $value];
        }

        if (is_int($value)) {
            return ['integerValue' => (string) $value];
        }

        if (is_float($value)) {
            return ['doubleValue' => $value];
        }

        if ($value instanceof \DateTimeInterface) {
            $instant = \DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new \DateTimeZone('UTC'));

            return ['timestampValue' => $instant->format('Y-m-d\TH:i:s.u\Z')];
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return [
                    'arrayValue' => $value === []
                        ? new \stdClass
                        : ['values' => array_map(fn (mixed $item) => $this->encodeValue($item), $value)],
                ];
            }

            return ['mapValue' => ['fields' => $this->encodeFields($value)]];
        }

        return ['stringValue' => (string) $value];
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private function decodeFields(array $fields): array
    {
        $decoded = [];

        foreach ($fields as $key => $value) {
            $decoded[(string) $key] = is_array($value) ? $this->decodeValue($value) : $value;
        }

        return $decoded;
    }

    private function decodeValue(array $value): mixed
    {
        if (array_key_exists('stringValue', $value)) {
            return $value['stringValue'];
        }

        if (array_key_exists('integerValue', $value)) {
            return (int) $value['integerValue'];
        }

        if (array_key_exists('doubleValue', $value)) {
            return (float) $value['doubleValue'];
        }

        if (array_key_exists('booleanValue', $value)) {
            return (bool) $value['booleanValue'];
        }

        if (array_key_exists('timestampValue', $value)) {
            return $value['timestampValue'];
        }

        if (array_key_exists('mapValue', $value)) {
            $fields = $value['mapValue']['fields'] ?? [];

            return is_array($fields) ? $this->decodeFields($fields) : [];
        }

        if (array_key_exists('arrayValue', $value)) {
            $items = $value['arrayValue']['values'] ?? [];

            return is_array($items)
                ? array_map(fn (mixed $item) => is_array($item) ? $this->decodeValue($item) : $item, $items)
                : [];
        }

        return null;
    }
}
