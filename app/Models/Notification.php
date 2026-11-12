<?php

namespace App\Models;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Support\Facades\Route;

/**
 * Notificación push/visual de un usuario.
 *
 * Usa la tabla estándar `notifications`, ampliada con los campos de entrega
 * (canal, sonido, aviso visual, enlace y origen) para que el panel de control
 * pueda contar el tráfico y respetar las preferencias sensoriales.
 *
 * @property array<string, mixed> $data
 */
class Notification extends DatabaseNotification
{
    /**
     * @var array<string, string>
     */
    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
        'sound' => 'boolean',
        'visual' => 'boolean',
    ];

    protected static string $collectionClass = DatabaseNotificationCollection::class;

    public function user(): ?User
    {
        return $this->notifiable instanceof User ? $this->notifiable : null;
    }

    /**
     * Título corto para la campana de notificaciones.
     */
    public function title(): string
    {
        $data = is_array($this->data) ? $this->data : [];

        return (string) ($data['title'] ?? 'Notificación');
    }

    public function body(): string
    {
        $data = is_array($this->data) ? $this->data : [];

        return (string) ($data['body'] ?? '');
    }

    /**
     * Enlace al que lleva la notificación.
     *
     * Si quien la creó no guardó `url` (por ejemplo, las menciones del chat),
     * se usa la conversación del payload para abrir el chat directamente.
     */
    public function link(): ?string
    {
        if (filled($this->url)) {
            return $this->url;
        }

        $data = is_array($this->data) ? $this->data : [];
        $conversationId = $data['conversation_id'] ?? null;

        if (! is_numeric($conversationId) || (int) $conversationId <= 0) {
            return null;
        }

        return Route::has('chat.index')
            ? route('chat.index').'?conversacion='.(int) $conversationId
            : null;
    }
}
