<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Services\Firestore\FirestoreService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Crea notificaciones push/visuales y las espeja en Firestore.
 *
 * Respeta dos niveles de control:
 *  - global (NotificationSetting), administrado desde Control de Notificaciones;
 *  - individual (NotificationPreference), que cada usuario ajusta en la
 *    campana o en el chat.
 *
 * El sonido nunca se impone: si el usuario o el administrador lo apagan, la
 * notificación queda solo como aviso visual dentro de la aplicación.
 */
class PushNotifier
{
    public function __construct(private readonly FirestoreService $firestore) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function send(
        User $user,
        string $type,
        string $title,
        string $body,
        array $data = [],
        ?string $url = null,
        string $source = 'sistema'
    ): ?Notification {
        $resolved = $this->resolve($user);

        if (! $resolved['push'] && ! $resolved['in_app']) {
            return null;
        }

        try {
            $notification = new Notification;
            $notification->id = (string) Str::uuid();
            $notification->type = $type;
            $notification->notifiable_type = $user->getMorphClass();
            $notification->notifiable_id = $user->getKey();
            $notification->data = array_merge($data, [
                'title' => $title,
                'body' => $body,
                'source' => $source,
            ]);
            $notification->channel = 'app';
            $notification->sound = $resolved['sound'];
            $notification->visual = $resolved['in_app'];
            $notification->url = $url;
            $notification->source = $source;
            $notification->read_at = null;
            $notification->save();
        } catch (Throwable $exception) {
            Log::error('No se pudo crear la notificación push', [
                'user_id' => $user->id,
                'type' => $type,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        $this->firestore->recordPushes([
            ['notification' => $notification, 'user' => $user],
        ]);

        return $notification;
    }

    /**
     * Envía la misma notificación a varias personas y sincroniza en un lote.
     *
     * @param  iterable<int, User>  $users
     * @param  array<string, mixed>  $data
     */
    public function sendMany(
        iterable $users,
        string $type,
        string $title,
        string $body,
        array $data = [],
        ?string $url = null,
        string $source = 'sistema'
    ): int {
        $recipients = Collection::make($users)->unique('id')->values();

        if ($recipients->isEmpty()) {
            return 0;
        }

        $created = [];
        $mirror = [];

        foreach ($recipients as $user) {
            $resolved = $this->resolve($user);

            if (! $resolved['push'] && ! $resolved['in_app']) {
                continue;
            }

            try {
                $notification = new Notification;
                $notification->id = (string) Str::uuid();
                $notification->type = $type;
                $notification->notifiable_type = $user->getMorphClass();
                $notification->notifiable_id = $user->getKey();
                $notification->data = array_merge($data, [
                    'title' => $title,
                    'body' => $body,
                    'source' => $source,
                ]);
                $notification->channel = 'app';
                $notification->sound = $resolved['sound'];
                $notification->visual = $resolved['in_app'];
                $notification->url = $url;
                $notification->source = $source;
                $notification->read_at = null;
                $notification->save();

                $created[] = $notification;
                $mirror[] = ['notification' => $notification, 'user' => $user];
            } catch (Throwable $exception) {
                Log::error('No se pudo crear la notificación push', [
                    'user_id' => $user->id,
                    'type' => $type,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if ($mirror !== []) {
            $this->firestore->recordPushes($mirror);
        }

        return count($created);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function sendToAdmins(
        string $type,
        string $title,
        string $body,
        array $data = [],
        ?string $url = null,
        string $source = 'sistema',
        ?User $except = null
    ): int {
        $admins = User::query()
            ->where('role', UserRole::Admin)
            ->where('is_active', true)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->id))
            ->get();

        return $this->sendMany($admins, $type, $title, $body, $data, $url, $source);
    }

    /**
     * @return array{push:bool,sound:bool,chat_sound:bool,in_app:bool}
     */
    public function resolve(User $user): array
    {
        return NotificationPreference::forUser($user)->resolved(NotificationSetting::current());
    }
}
