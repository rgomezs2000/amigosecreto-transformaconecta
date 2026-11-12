<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\NotificationSetting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class NotificacionesController extends Controller
{
    private function ensureAccess(): void
    {
        $module = Module::query()
            ->where('slug', 'control-notificaciones')
            ->where('is_active', true)
            ->first();

        if ($module === null) {
            abort(404);
        }

        if (! auth()->user()?->hasModuleAccess($module)) {
            abort(403);
        }
    }

    public function index(): View
    {
        $this->ensureAccess();

        return view('notificaciones.index', [
            'settings' => NotificationSetting::current(),
        ]);
    }

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
                'redirect' => route('notificaciones.index'),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al guardar las notificaciones', ['error' => $exception->getMessage()]);

            return api_error('No se pudo guardar la configuración. Intenta de nuevo.', 500);
        }
    }
}
