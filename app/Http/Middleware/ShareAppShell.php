<?php

namespace App\Http\Middleware;

use App\Models\Event;
use App\Models\Module;
use App\Models\NotificationPreference;
use App\Models\NotificationSetting;
use App\Support\FaseEvento;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class ShareAppShell
{
    /**
     * Comparte con todas las vistas autenticadas los datos de la cabecera,
     * el menú y las notificaciones.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            $user = Auth::user();

            if ($user->exists) {
                $user->forceFill(['last_seen_at' => now()])->save();
            }

            $event = Event::query()->orderByDesc('year')->first();
            $frozen = $event?->isSuspended() ?? false;

            $menuModules = $user->accessibleModules()
                ->whereNotIn('code', ['login', 'logout', 'notificaciones'])
                ->load('category')
                ->reject(fn (Module $module) => $frozen && $module->category?->slug === 'amigo-secreto')
                ->sortBy(fn (Module $module) => [
                    $module->category?->sort_order ?? 9999,
                    $module->order,
                ])
                ->values();

            // Inicio solo va en el menú, no en las tarjetas del panel.
            $modules = $menuModules
                ->reject(fn (Module $module) => $module->code === 'inicio' || blank($module->slug))
                ->values();

            $groups = $modules->groupBy(fn (Module $module) => $this->groupName($module));
            $menuGroups = $menuModules->groupBy(fn (Module $module) => $this->groupName($module));
            $event = Event::query()->orderByDesc('year')->first();

            $notificationsModule = Module::activeByCode('notificaciones');

            $showNotifications = $notificationsModule !== null
                && $user->hasModuleAccess($notificationsModule);

            $notifications = $showNotifications
                ? $user->notifications()->limit(20)->get()
                : collect();

            $notificationsCount = $showNotifications
                ? $user->unreadNotifications()->count()
                : 0;

            $notificationSettings = NotificationSetting::current();
            $notificationPreferences = NotificationPreference::forUser($user)->resolved($notificationSettings);

            $dollarRate = dollar_rate();

            View::share('shell', [
                'user' => $user,
                'modules' => $modules,
                'groups' => $groups,
                'menuGroups' => $menuGroups,
                'event' => $event,
                'currentStep' => FaseEvento::paso($event),
                'showNotifications' => $showNotifications,
                'notifications' => $notifications,
                'notificationsCount' => $notificationsCount,
                'notificationSettings' => $notificationSettings,
                'notificationPreferences' => $notificationPreferences,
                'canManageNotifications' => $menuModules->contains('code', 'control-notificaciones'),
                'dollarRate' => $dollarRate,
                // Invitación a un grupo pendiente de mostrar como modal al entrar.
                'chatInvitation' => $request->session()->get('chat.invitation'),
                // Motivo por el que un enlace ya no sirve: expirado, usado o eliminado.
                'chatInvitationNotice' => $request->session()->pull('chat.invitation_notice'),
            ]);
        }

        return $next($request);
    }

    private function groupName(Module $module): string
    {
        return $module->category?->name ?? $module->group;
    }
}
