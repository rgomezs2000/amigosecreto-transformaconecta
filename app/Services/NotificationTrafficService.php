<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\ChatReport;
use App\Models\Conversation;
use App\Models\EmailLog;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cuenta el tráfico de notificaciones push, correos y chat para el panel de
 * Control de Notificaciones.
 *
 * Usa SQL (fuente de verdad) para que las cifras no dependan de que Firebase
 * esté disponible.
 */
class NotificationTrafficService
{
    /**
     * @return array<string, array<string, int>>
     */
    public function summary(): array
    {
        return [
            'push' => $this->periods(Notification::query()),
            'emails' => array_merge($this->periods(EmailLog::query()), [
                'failed' => EmailLog::query()->where('status', EmailLog::STATUS_FAILED)->count(),
                'skipped' => EmailLog::query()->where('status', EmailLog::STATUS_SKIPPED)->count(),
            ]),
            'messages' => $this->periods(ChatMessage::query()),
            'reports' => $this->periods(ChatReport::query()),
            'conversations' => [
                'direct' => Conversation::query()->where('type', 'direct')->count(),
                'group' => Conversation::query()->where('type', 'group')->count(),
                'reports' => Conversation::query()->where('type', 'reports')->count(),
            ],
        ];
    }

    /**
     * @return array{today:int,week:int,total:int}
     */
    private function periods(Builder $query): array
    {
        $today = now()->startOfDay();
        $week = now()->subDays(6)->startOfDay();

        return [
            'today' => (clone $query)->where('created_at', '>=', $today)->count(),
            'week' => (clone $query)->where('created_at', '>=', $week)->count(),
            'total' => (clone $query)->count(),
        ];
    }
}
