<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Services\Firestore\FirestoreService;
use App\Services\NotificationTrafficService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class NotificacionesController extends Controller
{
    public function __construct(
        private readonly FirestoreService $firestore,
        private readonly NotificationTrafficService $traffic,
    ) {}

    /**
     * Control global (solo administración).
     */
    private function ensureAccess(): void
    {
        $module = Module::activeByCode('control-notificaciones');

        if ($module === null) {
            abort(404);
        }

        if (! auth()->user()?->hasModuleAccess($module)) {
            abort(403);
        }
    }

    /**
     * Campana personal del usuario.
     */
    private function ensureBellAccess(): void
    {
        $module = Module::activeByCode('notificaciones');

        if ($module === null) {
            abort(404);
        }

        if (! auth()->user()?->hasModuleAccess($module)) {
            abort(403);
        }
    }

    /**
     * Preferencias individuales: basta con tener campana o chat, porque el
     * interruptor de sonido también vive en el chat.
     */
    private function ensurePreferenceAccess(): void
    {
        $user = auth()->user();

        foreach (['notificaciones', 'chat'] as $code) {
            $module = Module::activeByCode($code);

            if ($module !== null && $user?->hasModuleAccess($module)) {
                return;
            }
        }

        abort(403);
    }

    public function index(): View
    {
        $this->ensureAccess();

        $settings = NotificationSetting::current();

        $users = User::query()
            ->with('notificationPreference')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => $this->userPreferencesData($user, $settings));

        return view('notificaciones.index', [
            'settings' => $settings,
            'traffic' => $this->traffic->summary(),
            'users' => $users,
            'firestore' => $this->firestore->status(),
        ]);
    }

    /**
     * Contadores de tráfico para actualizar el panel sin recargarlo.
     */
    public function contadores(): JsonResponse
    {
        $this->ensureAccess();

        return api_success('Contadores de notificaciones.', [
            'traffic' => $this->traffic->summary(),
            'firestore' => $this->firestore->status(),
        ]);
    }

    /**
     * Guarda la configuración global de notificaciones.
     */
    public function guardar(Request $request): JsonResponse
    {
        $this->ensureAccess();

        $validator = Validator::make($request->all(), [
            'email_enabled' => ['required', 'boolean'],
            'push_enabled' => ['required', 'boolean'],
            'sound_enabled' => ['required', 'boolean'],
            'in_app_enabled' => ['required', 'boolean'],
        ], [
            '*.required' => 'Falta un valor de configuración.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), [
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        try {
            NotificationSetting::current()->update($data);

            $request->session()->flash('notificaciones_status', 'Configuración de notificaciones guardada.');

            return api_success('Configuración guardada correctamente.', [
                'redirect' => module_url('control-notificaciones'),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al guardar las notificaciones', ['error' => $exception->getMessage()]);

            return api_error('No se pudo guardar la configuración. Intenta de nuevo.', 500);
        }
    }

    /**
     * Preferencias del usuario autenticado (campana y chat).
     */
    public function preferencias(Request $request): JsonResponse
    {
        $this->ensurePreferenceAccess();

        $user = $request->user();

        return $this->savePreferences($request, $user);
    }

    /**
     * Preferencias individuales de otro usuario (solo administración).
     */
    public function preferenciasUsuario(Request $request, User $user): JsonResponse
    {
        $this->ensureAccess();

        return $this->savePreferences($request, $user);
    }

    /**
     * Contador de notificaciones nuevas para la campana.
     */
    public function contador(Request $request): JsonResponse
    {
        $this->ensureBellAccess();

        $user = $request->user();
        $resolved = NotificationPreference::forUser($user)->resolved(NotificationSetting::current());
        $latest = $user->notifications()->first();

        return api_success('Contador de notificaciones.', [
            'count' => $user->unreadNotifications()->count(),
            'latest_id' => $latest?->id,
            'latest_at' => $latest?->created_at?->toIso8601String(),
            'sound' => $resolved['sound'],
            'in_app' => $resolved['in_app'],
        ]);
    }

    /**
     * Listado para refrescar la campana sin recargar la página.
     */
    public function lista(Request $request): JsonResponse
    {
        $this->ensureBellAccess();

        $user = $request->user();

        $notifications = $user->notifications()
            ->limit(20)
            ->get()
            ->map(fn (Notification $notification) => [
                'id' => $notification->id,
                'title' => $notification->title(),
                'body' => $notification->body(),
                'date' => $notification->created_at?->format('d/m/Y H:i') ?? '',
                'url' => $notification->link(),
                'read' => $notification->read_at !== null,
            ])
            ->values();

        return api_success('Notificaciones.', [
            'count' => $user->unreadNotifications()->count(),
            'notifications' => $notifications,
        ]);
    }

    /**
     * Marca una notificación propia como leída.
     */
    public function leer(Request $request, Notification $notification): JsonResponse
    {
        $this->ensureBellAccess();

        $user = $request->user();

        if ($notification->notifiable_type !== $user->getMorphClass()
            || (int) $notification->notifiable_id !== (int) $user->getKey()) {
            abort(404);
        }

        if ($notification->read_at === null) {
            $notification->markAsRead();
            $this->firestore->markPushRead($notification);
        }

        return api_success('Notificación leída.', [
            'id' => $notification->id,
            'url' => $notification->link(),
            'count' => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * Marca todas las notificaciones propias como leídas.
     */
    public function leerTodas(Request $request): JsonResponse
    {
        $this->ensureBellAccess();

        $user = $request->user();
        $unread = $user->unreadNotifications()->get();

        if ($unread->isNotEmpty()) {
            $user->unreadNotifications()->update(['read_at' => now()]);
            $this->firestore->markPushesRead($unread);
        }

        return api_success('Notificaciones marcadas como leídas.', [
            'count' => 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function userPreferencesData(User $user, NotificationSetting $settings): array
    {
        $preference = $user->notificationPreference;
        $model = $preference ?? new NotificationPreference(['user_id' => $user->id]);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role?->label() ?? 'Usuario',
            'preferences' => $model->resolved($settings),
            'overrides' => [
                'push_enabled' => $preference?->push_enabled,
                'sound_enabled' => $preference?->sound_enabled,
                'chat_sound_enabled' => $preference?->chat_sound_enabled,
                'in_app_enabled' => $preference?->in_app_enabled,
            ],
            'inherits' => $preference === null || (
                $preference->push_enabled === null
                && $preference->sound_enabled === null
                && $preference->chat_sound_enabled === null
                && $preference->in_app_enabled === null
            ),
        ];
    }

    private function savePreferences(Request $request, User $user): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'push_enabled' => ['sometimes', 'nullable', 'boolean'],
            'sound_enabled' => ['sometimes', 'nullable', 'boolean'],
            'chat_sound_enabled' => ['sometimes', 'nullable', 'boolean'],
            'in_app_enabled' => ['sometimes', 'nullable', 'boolean'],
            'reset' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), [
                'errors' => $validator->errors(),
            ], 422);
        }

        $fields = [
            'push_enabled',
            'sound_enabled',
            'chat_sound_enabled',
            'in_app_enabled',
        ];

        try {
            if ($request->boolean('reset')) {
                $values = array_fill_keys($fields, null);
            } else {
                $values = collect($validator->validated())
                    ->only($fields)
                    ->all();

                if ($values === []) {
                    return api_error('No hay preferencias para guardar.', 422);
                }
            }

            $preference = NotificationPreference::query()->updateOrCreate(
                ['user_id' => $user->id],
                $values
            );

            $settings = NotificationSetting::current();

            return api_success('Preferencias actualizadas.', [
                'preferences' => $preference->resolved($settings),
                'overrides' => $values,
                'user_id' => $user->id,
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al guardar las preferencias de notificación', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);

            return api_error('No se pudieron guardar las preferencias. Intenta de nuevo.', 500);
        }
    }
}
