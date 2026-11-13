<?php

namespace App\Services;

use App\Models\ChatReport;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Avisos de un caso de reporte (ticket) por sus fases.
 *
 * - Abierto (por atender): el beneficiario recibe correo y push.
 * - En atención: el administrador que lo gestiona recibe el correo del caso y
 *   el beneficiario recibe el aviso de la nueva fase.
 * - Cerrado: el beneficiario recibe el correo y el push de cierre.
 */
class ReporteAvisos
{
    public function __construct(private readonly PushNotifier $push) {}

    public function abierto(ChatReport $report, Conversation $conversation): void
    {
        $beneficiary = $report->reporter;

        if ($beneficiary === null) {
            return;
        }

        $ticket = $report->id;
        $url = $this->url($conversation);

        $this->correo(
            $beneficiary,
            'Caso Nº '.$ticket.' abierto (por atender)',
            'Tu caso Nº '.$ticket.' quedó registrado',
            [
                'El caso está abierto y por atender.',
                'Ticket: Nº '.$ticket,
                'Motivo: '.$this->motivo($report),
                'Fecha: '.now()->format('d/m/Y H:i'),
                'El chat del caso está en solo lectura hasta que un administrador lo tome.',
            ],
            $url
        );

        $this->push->send(
            $beneficiary,
            'chat.reporte',
            'Caso Nº '.$ticket.' abierto',
            'Tu caso quedó registrado y está por atender. El chat se habilita cuando se tome.',
            $this->datosPush($report, $conversation, 'por_atender'),
            $url,
            'reporte'
        );
    }

    /**
     * Correo del caso para el administrador que lo va a gestionar.
     */
    public function gestion(ChatReport $report, Conversation $conversation, User $admin): void
    {
        $ticket = $report->id;
        $reporter = $report->reporter?->name ?? '—';
        $author = $report->message?->user?->name ?? '—';
        $conversationName = $report->message?->conversation?->name
            ?? ($report->message?->conversation?->type === 'group' ? 'Grupo' : 'Conversación');

        send_mail(
            $admin->email,
            'Caso Nº '.$ticket.' para gestionar',
            'Caso Nº '.$ticket.' asignado a tu gestión',
            [
                'Tomaste este caso. Estos son los datos para gestionarlo.',
                'Ticket: Nº '.$ticket,
                'Reportado por: '.$reporter,
                'Autor del mensaje: '.$author,
                'Conversación: '.$conversationName,
                'Motivo: '.$this->motivo($report),
                'Fecha: '.$report->created_at?->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i'),
            ],
            ['label' => 'Abrir chat del caso', 'url' => $this->url($conversation)],
            [
                'recipient_name' => $admin->name,
                'eyebrow' => 'Reporte',
                'preheader' => 'Caso Nº '.$ticket.' en gestión',
                'source' => 'reporte',
            ]
        );
    }

    public function enAtencion(ChatReport $report, Conversation $conversation, User $admin): void
    {
        $beneficiary = $report->reporter;

        if ($beneficiary === null || (int) $beneficiary->id === (int) $admin->id) {
            return;
        }

        $ticket = $report->id;
        $url = $this->url($conversation);

        $this->correo(
            $beneficiary,
            'Caso Nº '.$ticket.' en atención',
            'Tu caso Nº '.$ticket.' está en atención',
            [
                'El administrador '.$admin->name.' tomó tu caso.',
                'Ticket: Nº '.$ticket,
                'Motivo: '.$this->motivo($report),
                'Fecha: '.now()->format('d/m/Y H:i'),
                'El chat del caso ya está habilitado para que puedas responder.',
            ],
            $url
        );

        $this->push->send(
            $beneficiary,
            'chat.reporte',
            'Caso Nº '.$ticket.' en atención',
            'El administrador '.$admin->name.' tomó tu caso y abrió el chat.',
            $this->datosPush($report, $conversation, 'atendiendo'),
            $url,
            'reporte'
        );
    }

    /**
     * El caso pasa a otro administrador: se le envía la ficha con el motivo de
     * la remisión y el beneficiario se entera del nuevo gestor.
     */
    public function remitido(
        ChatReport $report,
        Conversation $conversation,
        User $from,
        User $to,
        string $observation
    ): void {
        $ticket = $report->id;
        $url = $this->url($conversation);

        send_mail(
            $to->email,
            'Caso Nº '.$ticket.' remitido a tu gestión',
            'Caso Nº '.$ticket.' remitido a tu gestión',
            [
                'El administrador '.$from->name.' te remitió este caso.',
                'Ticket: Nº '.$ticket,
                'Reportado por: '.($report->reporter?->name ?? '—'),
                'Motivo del caso: '.$this->motivo($report),
                'Observación de la remisión: '.Str::limit($observation, 300),
                'Fecha: '.now()->format('d/m/Y H:i'),
            ],
            ['label' => 'Abrir chat del caso', 'url' => $url],
            [
                'recipient_name' => $to->name,
                'eyebrow' => 'Reporte',
                'preheader' => 'Caso Nº '.$ticket.' remitido',
                'source' => 'reporte',
            ]
        );

        $this->push->send(
            $to,
            'chat.reporte',
            'Caso Nº '.$ticket.' remitido a tu gestión',
            'El administrador '.$from->name.' te remitió el caso.',
            $this->datosPush($report, $conversation, 'remitido'),
            $url,
            'reporte'
        );

        $beneficiary = $report->reporter;

        if ($beneficiary === null
            || (int) $beneficiary->id === (int) $to->id
            || (int) $beneficiary->id === (int) $from->id) {
            return;
        }

        $this->correo(
            $beneficiary,
            'Caso Nº '.$ticket.' remitido a otro administrador',
            'Tu caso Nº '.$ticket.' pasó a otro gestor',
            [
                'El caso ahora lo gestiona el administrador '.$to->name.'.',
                'Ticket: Nº '.$ticket,
                'Motivo: '.$this->motivo($report),
                'Fecha: '.now()->format('d/m/Y H:i'),
                'El chat del caso sigue habilitado para que puedas responder.',
            ],
            $url
        );

        $this->push->send(
            $beneficiary,
            'chat.reporte',
            'Caso Nº '.$ticket.' con otro gestor',
            'El administrador '.$to->name.' ahora gestiona tu caso.',
            $this->datosPush($report, $conversation, 'remitido'),
            $url,
            'reporte'
        );
    }

    public function cerrado(ChatReport $report, Conversation $conversation, User $admin): void
    {
        $beneficiary = $report->reporter;

        if ($beneficiary === null || (int) $beneficiary->id === (int) $admin->id) {
            return;
        }

        $ticket = $report->id;
        $url = $this->url($conversation);
        $observacion = trim((string) ($report->close_observation ?? ''));

        $lines = [
            'El administrador '.$admin->name.' cerró tu caso.',
            'Ticket: Nº '.$ticket,
            'Motivo: '.$this->motivo($report),
            'Fecha: '.now()->format('d/m/Y H:i'),
        ];

        if ($observacion !== '') {
            $lines[] = 'Observación de cierre: '.Str::limit($observacion, 300);
        }

        $lines[] = 'El chat del caso quedó en solo lectura.';

        $this->correo(
            $beneficiary,
            'Caso Nº '.$ticket.' cerrado',
            'Tu caso Nº '.$ticket.' fue cerrado',
            $lines,
            $url
        );

        $this->push->send(
            $beneficiary,
            'chat.reporte',
            'Caso Nº '.$ticket.' cerrado',
            'El administrador '.$admin->name.' cerró tu caso. El chat quedó en solo lectura.',
            $this->datosPush($report, $conversation, 'cerrado'),
            $url,
            'reporte'
        );
    }

    private function correo(User $user, string $subject, string $title, array $lines, string $url): void
    {
        send_mail(
            $user->email,
            $subject,
            $title,
            $lines,
            ['label' => 'Abrir chat del caso', 'url' => $url],
            [
                'recipient_name' => $user->name,
                'eyebrow' => 'Reporte',
                'preheader' => $subject,
                'source' => 'reporte',
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function datosPush(ChatReport $report, Conversation $conversation, string $phase): array
    {
        return [
            'report_id' => $report->id,
            'conversation_id' => $conversation->id,
            'report_phase' => $phase,
        ];
    }

    private function motivo(ChatReport $report): string
    {
        return Str::limit(trim(strip_tags((string) $report->reason)), 160);
    }

    private function url(Conversation $conversation): string
    {
        return module_url('chat').'?conversacion='.$conversation->id;
    }
}
