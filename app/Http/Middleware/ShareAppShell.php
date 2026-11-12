<?php

namespace App\Http\Middleware;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Module;
use App\Models\NotificationPreference;
use App\Models\NotificationSetting;
use App\Services\ExchangeRateService;
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
                ->whereNotIn('slug', ['login', 'logout', 'notificaciones'])
                ->load('category')
                ->reject(fn (Module $module) => $frozen && $module->category?->slug === 'amigo-secreto')
                ->sortBy(fn (Module $module) => [
                    $module->category?->sort_order ?? 9999,
                    $module->order,
                ])
                ->values();

            // Inicio solo va en el menú, no en las tarjetas del panel.
            $modules = $menuModules
                ->whereNotIn('slug', [''])
                ->values();

            $groups = $modules->groupBy(fn (Module $module) => $this->groupName($module));
            $menuGroups = $menuModules->groupBy(fn (Module $module) => $this->groupName($module));
            $event = Event::query()->orderByDesc('year')->first();

            $notificationsModule = Module::query()
                ->where('slug', 'notificaciones')
                ->first();

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

            $dollar = bcv_dollar();
            $dollarRate = $dollar['promedio']
                ?? app(ExchangeRateService::class)->latest()?->rate;

            View::share('shell', [
                'user' => $user,
                'modules' => $modules,
                'groups' => $groups,
                'menuGroups' => $menuGroups,
                'event' => $event,
                'currentStep' => $this->currentStep($event),
                'showNotifications' => $showNotifications,
                'notifications' => $notifications,
                'notificationsCount' => $notificationsCount,
                'notificationSettings' => $notificationSettings,
                'notificationPreferences' => $notificationPreferences,
                'canManageNotifications' => $menuModules->contains('slug', 'control-notificaciones'),
                'dollarRate' => $dollarRate,
                // Invitación a un grupo pendiente de mostrar como modal al entrar.
                'chatInvitation' => $request->session()->get('chat.invitation'),
            ]);
        }

        return $next($request);
    }

    private function groupName(Module $module): string
    {
        return $module->category?->name ?? $module->group;
    }

    private function currentStep(?Event $event): int
    {
        if ($event === null) {
            return 1;
        }

        if (in_array($event->status, [EventStatus::Draft, EventStatus::Registration], true)) {
            return 1;
        }

        if ($event->status === EventStatus::DrawEnabled) {
            return 2;
        }

        if ($event->status === EventStatus::Drawn) {
            if ($event->exchange_at !== null && $event->exchange_at->isPast()) {
                return 5;
            }

            if ($event->dynamic_starts_at !== null && $event->dynamic_starts_at->isPast()) {
                return 4;
            }

            return 3;
        }

        return 5;
    }
}
