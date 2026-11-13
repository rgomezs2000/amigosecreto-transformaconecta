<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesModeracion;
use App\Models\ChatBlock;
use App\Models\ChatReport;
use App\Models\Conversation;
use App\Models\GroupBan;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Pantalla del módulo de Moderación y sus reportes del chat.
 *
 * La pantalla tiene tres pestañas: Reportes (solo lectura: muestra los
 * reportes recopilados y enlaza al chat para revisar la conversación),
 * Bloqueos entre usuarios y Vetados de grupo. Los dos últimos apartados
 * viven en sus propios controladores.
 */
class ModeracionController extends Controller
{
    use AuthorizesModeracion;

    public function index(): View
    {
        $this->ensureModeracionAccess();

        return view('moderacion.index', [
            'total' => ChatReport::query()->count(),
            'pendientes' => ChatReport::query()->where('status', ChatReport::STATUS_PENDING)->count(),
            'atendiendo' => ChatReport::query()->where('status', ChatReport::STATUS_ATTENDING)->count(),
            'cerrados' => ChatReport::query()->where('status', ChatReport::STATUS_CLOSED)->count(),
            'usuarios' => ChatBlock::query()->count(),
            'grupos' => GroupBan::query()->count(),
        ]);
    }

    /**
     * Contadores de las tres pestañas para actualizarlos sin recargar.
     */
    public function contadores(): JsonResponse
    {
        $this->ensureModeracionAccess();

        return api_success('Contadores de moderación.', [
            'reports' => ChatReport::query()->count(),
            'pending' => ChatReport::query()->where('status', ChatReport::STATUS_PENDING)->count(),
            'attending' => ChatReport::query()->where('status', ChatReport::STATUS_ATTENDING)->count(),
            'closed' => ChatReport::query()->where('status', ChatReport::STATUS_CLOSED)->count(),
            'blocks' => ChatBlock::query()->count(),
            'bans' => GroupBan::query()->count(),
        ]);
    }

    public function datos(Request $request): JsonResponse
    {
        $this->ensureModeracionAccess();

        $draw = (int) $request->input('draw', 1);
        $start = max(0, (int) $request->input('start', 0));
        $length = min(100, max(1, (int) $request->input('length', 10)));
        $search = trim((string) $request->input('search.value', ''));

        $recordsTotal = ChatReport::query()->count();

        $query = ChatReport::query()
            ->with(['message.conversation.users', 'message.user', 'reporter', 'assignedAdmin', 'closedBy']);

        if ($search !== '') {
            $like = '%'.$search.'%';

            $query->where(function ($query) use ($like) {
                $query->where('reason', 'like', $like)
                    ->orWhere('status', 'like', $like)
                    ->orWhereHas('reporter', fn ($user) => $user->where('name', 'like', $like))
                    ->orWhereHas('assignedAdmin', fn ($user) => $user->where('name', 'like', $like))
                    ->orWhereHas('message', fn ($message) => $message->where('body', 'like', $like))
                    ->orWhereHas('message.user', fn ($user) => $user->where('name', 'like', $like));
            });
        }

        $filterType = (string) $request->input('filter_type', 'all');

        if ($filterType === 'date') {
            $from = trim((string) $request->input('date_from', ''));
            $to = trim((string) $request->input('date_to', ''));

            try {
                if ($from !== '') {
                    $query->where('created_at', '>=', Carbon::parse($from));
                }

                if ($to !== '') {
                    $query->where('created_at', '<=', Carbon::parse($to));
                }
            } catch (\Throwable $exception) {
                // Una fecha inválida no debe romper la tabla: se ignora.
            }
        }

        if ($filterType === 'status') {
            $status = (string) $request->input('status', '');

            if (in_array($status, [
                ChatReport::STATUS_PENDING,
                ChatReport::STATUS_ATTENDING,
                ChatReport::STATUS_CLOSED,
            ], true)) {
                $query->where('status', $status);
            }
        }

        $recordsFiltered = (clone $query)->count();

        $column = (int) $request->input('order.0.column', 0);
        $direction = $request->input('order.0.dir') === 'asc' ? 'asc' : 'desc';

        $orderColumn = match ($column) {
            1 => 'status',
            2 => 'reporter_id',
            3 => 'message_id',
            4 => 'reason',
            default => 'created_at',
        };

        $reports = $query
            ->orderBy($orderColumn, $direction)
            ->orderByDesc('id')
            ->skip($start)
            ->take($length)
            ->get();

        $data = $reports->map(fn (ChatReport $report) => $this->rowData($report));

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }

    /**
     * Un administrador toma el caso: queda asociado a él y pasa a «Atendiendo».
     */
    public function tomar(Request $request, ChatReport $report): JsonResponse
    {
        $this->ensureModeracionAccess();

        $validator = validator($request->all(), [
            'attention_observation' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), ['errors' => $validator->errors()], 422);
        }

        $affected = ChatReport::query()
            ->whereKey($report->id)
            ->where('status', ChatReport::STATUS_PENDING)
            ->update([
                'status' => ChatReport::STATUS_ATTENDING,
                'assigned_to' => $request->user()->id,
                'taken_at' => now(),
                'attention_observation' => $request->input('attention_observation'),
            ]);

        if ($affected === 0) {
            return api_error('Otro administrador ya tomó este caso.', 409);
        }

        $report->refresh()->load(['message.conversation.users', 'message.user', 'reporter', 'assignedAdmin', 'closedBy']);

        return api_success('Caso tomado. Ahora puedes gestionarlo y cerrarlo.', [
            'report' => $this->rowData($report),
        ]);
    }

    /**
     * Solo el administrador que tomó el caso puede cerrarlo, con confirmación
     * explícita y observación de cierre.
     */
    public function cerrar(Request $request, ChatReport $report): JsonResponse
    {
        $this->ensureModeracionAccess();

        if (! $report->isAttending()) {
            return api_error('El caso no está en atención.', 422);
        }

        if ((int) $report->assigned_to !== (int) $request->user()->id) {
            return api_error('Solo el administrador que tomó el caso puede cerrarlo.', 403);
        }

        $validator = validator($request->all(), [
            'close_observation' => ['required', 'string', 'max:2000'],
            'confirmed' => ['accepted'],
        ], [
            'close_observation.required' => 'Escribe la observación de cierre.',
            'confirmed.accepted' => 'Confirma que el reporte quedó cerrado.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), ['errors' => $validator->errors()], 422);
        }

        $affected = ChatReport::query()
            ->whereKey($report->id)
            ->where('status', ChatReport::STATUS_ATTENDING)
            ->where('assigned_to', $request->user()->id)
            ->update([
                'status' => ChatReport::STATUS_CLOSED,
                'closed_at' => now(),
                'closed_by' => $request->user()->id,
                'close_observation' => $request->input('close_observation'),
            ]);

        if ($affected === 0) {
            return api_error('El caso cambió de estado. Recarga la lista.', 409);
        }

        $report->refresh()->load(['message.conversation.users', 'message.user', 'reporter', 'assignedAdmin', 'closedBy']);

        return api_success('Caso cerrado correctamente.', [
            'report' => $this->rowData($report),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rowData(ChatReport $report): array
    {
        $message = $report->message;
        $conversation = $message?->conversation;
        $conversationUrl = $conversation !== null
            ? module_url('chat').'?conversacion='.$conversation->id
            : null;
        $conversationLabel = $this->conversationLabel($conversation);
        $reporterName = $report->reporter?->name ?? '—';
        $authorName = $message?->user?->name ?? '—';
        $status = $report->status ?: ChatReport::STATUS_PENDING;
        $statusLabel = $report->statusLabel();
        $isMine = $report->assigned_to !== null
            && (int) $report->assigned_to === (int) auth()->id();

        return [
            'id' => $report->id,
            'date' => $report->created_at?->format('d/m/Y H:i') ?? '—',
            'date_order' => $report->created_at?->toIso8601String(),
            'status' => e($statusLabel),
            'status_label' => $statusLabel,
            'status_class' => $status,
            'status_html' => '<span class="moderacion-status moderacion-status--'.e($status).'">'.e($statusLabel).'</span>',
            'assigned_name' => e($report->assignedAdmin?->name ?? '—'),
            'assigned_raw' => $report->assignedAdmin?->name ?? '—',
            'taken_at' => $report->taken_at?->format('d/m/Y H:i') ?? '—',
            'attention_observation' => (string) ($report->attention_observation ?? ''),
            'closed_at' => $report->closed_at?->format('d/m/Y H:i') ?? '—',
            'closed_by_name' => $report->closedBy?->name ?? '—',
            'close_observation' => (string) ($report->close_observation ?? ''),
            'can_take' => $report->isPending(),
            'can_close' => $report->isAttending() && $isMine,
            'reporter' => e($reporterName),
            'reporter_raw' => $reporterName,
            'author' => e($authorName),
            'author_raw' => $authorName,
            'reason' => e(Str::limit((string) $report->reason, 90)),
            'reason_full' => (string) $report->reason,
            'message' => e(Str::limit(trim(strip_tags((string) $message?->body)), 90)),
            'message_full' => trim(strip_tags((string) $message?->body)),
            'conversation' => e($conversationLabel),
            'conversation_raw' => $conversationLabel,
            'conversation_url' => $conversationUrl,
            'actions' => view('moderacion.partials.actions', [
                'report' => $report,
                'conversationUrl' => $conversationUrl,
            ])->render(),
        ];
    }

    private function conversationLabel(?Conversation $conversation): string
    {
        if ($conversation === null) {
            return '—';
        }

        if ($conversation->type === 'reports') {
            return 'Canal de reportes';
        }

        if ($conversation->isGroup()) {
            return 'Grupo: '.($conversation->name ?: 'Sin nombre');
        }

        $names = $conversation->users->pluck('name')->filter()->implode(' / ');

        return $names !== '' ? 'Directa: '.$names : 'Conversación directa';
    }
}
