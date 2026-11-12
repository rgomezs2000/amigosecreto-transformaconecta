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
            ->with(['message.conversation.users', 'message.user', 'reporter']);

        if ($search !== '') {
            $like = '%'.$search.'%';

            $query->where(function ($query) use ($like) {
                $query->where('reason', 'like', $like)
                    ->orWhereHas('reporter', fn ($user) => $user->where('name', 'like', $like))
                    ->orWhereHas('message', fn ($message) => $message->where('body', 'like', $like))
                    ->orWhereHas('message.user', fn ($user) => $user->where('name', 'like', $like));
            });
        }

        $recordsFiltered = (clone $query)->count();

        $column = (int) $request->input('order.0.column', 0);
        $direction = $request->input('order.0.dir') === 'asc' ? 'asc' : 'desc';

        $orderColumn = match ($column) {
            1 => 'reporter_id',
            2 => 'message_id',
            3 => 'reason',
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
     * @return array<string, mixed>
     */
    private function rowData(ChatReport $report): array
    {
        $message = $report->message;
        $conversation = $message?->conversation;
        $conversationUrl = $conversation !== null
            ? route('chat.index').'?conversacion='.$conversation->id
            : null;
        $conversationLabel = $this->conversationLabel($conversation);
        $reporterName = $report->reporter?->name ?? '—';
        $authorName = $message?->user?->name ?? '—';

        return [
            'id' => $report->id,
            'date' => $report->created_at?->format('d/m/Y H:i') ?? '—',
            'date_order' => $report->created_at?->toIso8601String(),
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
