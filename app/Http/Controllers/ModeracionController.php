<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Controllers\Concerns\AuthorizesModeracion;
use App\Models\ChatBlock;
use App\Models\ChatReport;
use App\Models\ChatReportAttachment;
use App\Models\Conversation;
use App\Models\GroupBan;
use App\Models\Module;
use App\Models\User;
use App\Services\ReporteAvisos;
use App\Services\ReporteChat;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Centro de Ayuda y Soporte y sus casos.
 *
 * La pantalla tiene tres pestañas: Soporte (casos de moderación,
 * sugerencias, ayuda y soporte), Bloqueos entre usuarios y Vetados de grupo.
 * Los dos últimos apartados viven en sus propios controladores.
 */
class ModeracionController extends Controller
{
    use AuthorizesModeracion;

    public function __construct(
        private readonly ReporteChat $reporteChat,
        private readonly ReporteAvisos $reporteAvisos,
    ) {}

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
            // Administradores para los filtros «Gestionado por» y «Remitido a».
            // El username acompaña al nombre dentro del combobox de búsqueda.
            'admins' => User::query()
                ->where('role', UserRole::Admin)
                ->orderBy('name')
                ->get(['id', 'name', 'username']),
        ]);
    }

    /**
     * Contadores de las tres pestañas para actualizarlos sin recargar.
     */
    public function contadores(): JsonResponse
    {
        $this->ensureModeracionAccess();

        return api_success('Contadores del Centro de Ayuda y Soporte.', [
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
            ->with([
                'message.conversation.users',
                'message.user',
                'reporter',
                'assignedAdmin',
                'takenBy',
                'referredTo',
                'closedBy',
                'conversation.users',
                'referrals.fromAdmin',
                'referrals.toAdmin',
            ]);

        if ($search !== '') {
            $like = '%'.$search.'%';

            $query->where(function ($query) use ($like, $search) {
                $query->where('reason', 'like', $like)
                    ->orWhere('status', 'like', $like)
                    ->orWhere('type', 'like', $like)
                    ->orWhere('context_label', 'like', $like)
                    ->orWhereHas('reporter', fn ($user) => $user->where('name', 'like', $like))
                    ->orWhereHas('assignedAdmin', fn ($user) => $user->where('name', 'like', $like))
                    ->orWhereHas('takenBy', fn ($user) => $user->where('name', 'like', $like))
                    ->orWhereHas('referredTo', fn ($user) => $user->where('name', 'like', $like))
                    ->orWhereHas('message', fn ($message) => $message->where('body', 'like', $like))
                    ->orWhereHas('message.user', fn ($user) => $user->where('name', 'like', $like));

                if (ctype_digit($search)) {
                    $query->orWhereKey((int) $search);
                }
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

        if ($filterType === 'case_type') {
            $type = (string) $request->input('case_type', '');

            if (array_key_exists($type, ChatReport::TYPES)) {
                $query->where('type', $type);
            }
        }

        // «Gestionado por»: administrador que tomó y gestionó el caso
        // originalmente, aunque después lo haya remitido.
        if ($filterType === 'manager') {
            $manager = (int) $request->input('manager_id', 0);

            if ($manager > 0) {
                $query->where('taken_by', $manager);
            }
        }

        // «Remitido a»: último administrador al que se remitió el caso.
        if ($filterType === 'referred') {
            $referred = (int) $request->input('referred_to_id', 0);

            if ($referred > 0) {
                $query->where('referred_to_id', $referred);
            }
        }

        $recordsFiltered = (clone $query)->count();

        $column = (int) $request->input('order.0.column', 0);
        $direction = $request->input('order.0.dir') === 'asc' ? 'asc' : 'desc';

        $orderColumn = match ($column) {
            0 => 'id',
            1 => 'created_at',
            2 => 'status',
            3 => 'taken_by',
            4 => 'referred_to_id',
            5 => 'reporter_id',
            6 => 'message_id',
            7 => 'reason',
            9 => 'type',
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
     * Ficha completa de un caso: datos, adjuntos, enlaces y sugerencia.
     */
    public function detalle(ChatReport $report): JsonResponse
    {
        $this->ensureModeracionAccess();

        $report->load([
            'message.conversation.users',
            'message.user',
            'reporter',
            'assignedAdmin',
            'takenBy',
            'referredTo',
            'closedBy',
            'conversation.users',
            'conversation.messages.user',
            'referrals.fromAdmin',
            'referrals.toAdmin',
            'attachments',
            'links',
        ]);

        $attachments = $report->attachments->map(fn (ChatReportAttachment $attachment) => [
            'id' => (int) $attachment->id,
            'kind' => $attachment->kind,
            'name' => $attachment->name,
            'mime' => (string) $attachment->mime,
            'size' => (int) $attachment->size,
            'size_label' => $attachment->humanSize(),
            'url' => route('moderacion.adjuntos', ['attachment' => $attachment->id]),
            'is_evidence' => (bool) $attachment->is_evidence,
            'date' => $attachment->created_at?->format('d/m/Y H:i') ?? '—',
        ])->values()->all();

        $media = array_values(array_filter($attachments, fn (array $item) => in_array($item['kind'], [
            ChatReportAttachment::KIND_IMAGE,
            ChatReportAttachment::KIND_VIDEO,
            ChatReportAttachment::KIND_EVIDENCE,
        ], true)));

        $audios = array_values(array_filter($attachments, fn (array $item) => $item['kind'] === ChatReportAttachment::KIND_AUDIO));
        $documents = array_values(array_filter($attachments, fn (array $item) => $item['kind'] === ChatReportAttachment::KIND_DOCUMENT));

        $links = $report->links->map(fn ($link) => [
            'id' => (int) $link->id,
            'url' => (string) $link->url,
            'date' => $link->created_at?->format('d/m/Y H:i') ?? '—',
        ])->values()->all();

        $suggestionMessages = [];

        if ($report->isSuggestion() && $report->conversation !== null) {
            $suggestionMessages = $report->conversation->messages()
                ->with('user')
                ->orderBy('id')
                ->get()
                ->map(fn ($message) => [
                    'id' => (int) $message->id,
                    'body' => (string) $message->body,
                    'author' => $message->user?->name ?? 'Sistema',
                    'is_admin' => (bool) $message->user?->isAdmin(),
                    'date' => $message->created_at?->format('d/m/Y H:i') ?? '—',
                ])
                ->values()
                ->all();
        }

        return api_success('Detalle del caso.', [
            'report' => $this->rowData($report),
            'attachments' => [
                'media' => $media,
                'audios' => $audios,
                'documents' => $documents,
            ],
            'links' => $links,
            'suggestion_messages' => $suggestionMessages,
        ]);
    }

    /**
     * Sirve un archivo adjunto del caso (solo administración).
     */
    public function adjunto(ChatReportAttachment $attachment): BinaryFileResponse
    {
        $this->ensureModeracionAccess();

        $path = Storage::disk('local')->path($attachment->path);

        if (! is_file($path)) {
            abort(404);
        }

        $headers = [
            'Content-Type' => $attachment->mime ?: 'application/octet-stream',
        ];

        if ($attachment->isImage() || $attachment->isVideo() || $attachment->isAudio()) {
            return response()->file($path, $headers);
        }

        return response()->download($path, $attachment->name, $headers);
    }

    /**
     * Respuesta del administrador a una sugerencia.
     */
    public function sugerencia(Request $request, ChatReport $report): JsonResponse
    {
        $this->ensureModeracionAccess();

        if (! $report->isSuggestion()) {
            return api_error('Este caso no es una sugerencia.', 422);
        }

        if (! $report->isAttending()) {
            return api_error('Toma el caso antes de responder la sugerencia.', 422);
        }

        if ((int) $report->assigned_to !== (int) $request->user()->id) {
            return api_error('Solo el administrador que gestiona el caso puede responder.', 403);
        }

        $validator = validator($request->all(), [
            'body' => ['required', 'string', 'max:4000'],
        ], [
            'body.required' => 'Escribe la respuesta a la sugerencia.',
            'body.max' => 'La respuesta no puede pasar de 4000 caracteres.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), ['errors' => $validator->errors()], 422);
        }

        $message = $this->reporteChat->responderSugerencia(
            $report,
            $request->user(),
            $validator->validated()['body']
        );

        $this->reporteAvisos->sugerenciaRespondida($report, $message);

        return api_success('Respuesta enviada.', [
            'reply' => [
                'id' => (int) $message->id,
                'body' => (string) $message->body,
                'author' => $message->user?->name ?? 'Administración',
                'is_admin' => true,
                'date' => $message->created_at?->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i'),
            ],
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
                'taken_by' => $request->user()->id,
                'taken_at' => now(),
                'attention_observation' => $request->input('attention_observation'),
            ]);

        if ($affected === 0) {
            return api_error('Otro administrador ya tomó este caso.', 409);
        }

        $report->refresh()->load([
            'message.conversation.users',
            'message.user',
            'reporter',
            'assignedAdmin',
            'closedBy',
            'conversation.users',
            'referrals.fromAdmin',
            'referrals.toAdmin',
        ]);

        // Al tomar el caso el administrador entra al chat del ticket, se
        // habilita la escritura para ambos y salen los avisos de la fase.
        $conversation = $this->reporteChat->gestionar($report, $request->user());
        $report->setRelation('conversation', $conversation);
        $this->reporteAvisos->gestion($report, $conversation, $request->user());
        $this->reporteAvisos->enAtencion($report, $conversation, $request->user());

        return api_success('Caso tomado. Ahora puedes gestionarlo y cerrarlo.', [
            'report' => $this->rowData($report),
            'ticket_chat_id' => $conversation->id,
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

        $report->refresh()->load([
            'message.conversation.users',
            'message.user',
            'reporter',
            'assignedAdmin',
            'closedBy',
            'conversation.users',
            'referrals.fromAdmin',
            'referrals.toAdmin',
        ]);

        // Caso cerrado: el chat del ticket queda en solo lectura para ambos y
        // el beneficiario recibe el aviso de cierre.
        $conversation = $this->reporteChat->cerrar($report);

        if ($conversation !== null) {
            $conversation->load('users');
            $report->setRelation('conversation', $conversation);
            $this->reporteAvisos->cerrado($report, $conversation, $request->user());
        }

        return api_success('Caso cerrado correctamente.', [
            'report' => $this->rowData($report),
        ]);
    }

    /**
     * Administradores activos para el combobox de remisión.
     */
    public function administradores(Request $request): JsonResponse
    {
        $this->ensureModeracionAccess();

        $module = Module::activeByCode('moderacion');

        $items = User::query()
            ->where('role', UserRole::Admin)
            ->where('is_active', true)
            ->whereKeyNot($request->user()->id)
            ->orderBy('name')
            // Se leen todas las columnas: hasModuleAccess() necesita el perfil.
            ->get()
            // Solo se remite a quien puede entrar a Moderación.
            ->filter(fn (User $item) => $module !== null && $item->hasModuleAccess($module))
            ->map(fn (User $item) => [
                'id' => (int) $item->id,
                'name' => $item->name,
                'username' => $item->username,
            ])
            ->values();

        return api_success('Administradores.', ['items' => $items]);
    }

    /**
     * Remite el caso a otro administrador.
     *
     * Solo el gestor actual puede remitirlo. La observación explica el motivo
     * y queda en el historial; el chat del ticket pasa al nuevo gestor y el
     * anterior deja de tener acceso. El nuevo gestor puede, a su vez,
     * remitirlo (incluso de vuelta al anterior).
     */
    public function remitir(Request $request, ChatReport $report): JsonResponse
    {
        $this->ensureModeracionAccess();

        $user = $request->user();

        if (! $report->isAttending()) {
            return api_error('Solo se remiten casos en atención.', 422);
        }

        if ((int) $report->assigned_to !== (int) $user->id) {
            return api_error('Solo el administrador que gestiona el caso puede remitirlo.', 403);
        }

        $validator = validator($request->all(), [
            'admin_id' => ['required', 'integer', 'exists:users,id'],
            'observation' => ['required', 'string', 'max:1000'],
        ], [
            'admin_id.required' => 'Elige el administrador al que remites el caso.',
            'admin_id.exists' => 'El administrador elegido no existe.',
            'observation.required' => 'Escribe la observación de la remisión.',
            'observation.max' => 'La observación no puede pasar de 1000 caracteres.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), ['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        $target = User::query()
            ->whereKey((int) $data['admin_id'])
            ->where('role', UserRole::Admin)
            ->where('is_active', true)
            ->first();

        if ($target === null) {
            return api_error('Elige un administrador activo.', 422);
        }

        $moderacionModule = Module::activeByCode('moderacion');

        if ($moderacionModule === null || ! $target->hasModuleAccess($moderacionModule)) {
            return api_error('Ese administrador no tiene acceso a Moderación.', 422);
        }

        if ((int) $target->id === (int) $user->id) {
            return api_error('El caso ya está en tu gestión.', 422);
        }

        if ((int) $target->id === (int) $report->reporter_id) {
            return api_error('No puedes remitir el caso a su beneficiario.', 422);
        }

        DB::transaction(function () use ($report, $user, $target, $data) {
            $report->forceFill([
                'assigned_to' => $target->id,
                'referred_to_id' => $target->id,
            ])->save();

            $report->referrals()->create([
                'from_admin_id' => $user->id,
                'to_admin_id' => $target->id,
                'observation' => $data['observation'],
            ]);
        });

        // El chat del caso pasa al nuevo gestor; el anterior sale (salvo que
        // sea el propio beneficiario).
        $conversation = $this->reporteChat->trasladar($report, $user, $target);

        $report->refresh()->load([
            'message.conversation.users',
            'message.user',
            'reporter',
            'assignedAdmin',
            'closedBy',
            'referrals.fromAdmin',
            'referrals.toAdmin',
        ]);

        if ($conversation !== null) {
            $conversation->load('users');
            $report->setRelation('conversation', $conversation);
            $this->reporteAvisos->remitido($report, $conversation, $user, $target, $data['observation']);
        }

        return api_success('Caso remitido a '.$target->name.'.', [
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
        $conversationUrl = $conversation !== null && $report->isModeration()
            ? module_url('chat').'?conversacion='.$conversation->id
            : null;
        $conversationLabel = $this->conversationLabel($conversation);
        $reporterName = $report->reporter?->name ?? '—';
        $authorName = $message?->user?->name ?? '—';
        $status = $report->status ?: ChatReport::STATUS_PENDING;
        $statusLabel = $report->statusLabel();
        $typeLabel = $report->typeLabel();
        $isMine = $report->assigned_to !== null
            && (int) $report->assigned_to === (int) auth()->id();

        // Módulo o funcionalidad donde ocurrió el inconveniente (soporte).
        $moduleUrl = null;

        if (filled($report->module_code)) {
            $module = Module::activeByCode((string) $report->module_code);
            $moduleUrl = $module?->url();
        }

        if ($moduleUrl === null && filled($report->context_url)) {
            $moduleUrl = (string) $report->context_url;
        }

        // Gestor original y último remitido. Si el caso se tomó antes de esta
        // columna, se usa el asignado actual como respaldo.
        $managerName = $report->takenBy?->name
            ?? $report->assignedAdmin?->name
            ?? '—';
        $referredToName = $report->referredTo?->name ?? '—';

        // Historial de remisiones, de la más reciente a la más antigua.
        $referrals = $report->referrals
            ->sortByDesc('id')
            ->map(fn ($referral) => [
                'id' => (int) $referral->id,
                'date' => $referral->created_at?->format('d/m/Y H:i') ?? '—',
                'from' => $referral->fromAdmin?->name ?? '—',
                'to' => $referral->toAdmin?->name ?? '—',
                'observation' => (string) $referral->observation,
            ])
            ->values();

        // Chat del caso (ticket): solo lo ve quien está dentro. El beneficiario
        // y el administrador que lo tomó; otro administrador no entra hasta
        // tomarlo (aunque sí puede ver la ficha y monitorear el caso).
        $ticketChat = $report->conversation;
        $canOpenTicketChat = $ticketChat !== null
            && ! $report->isSuggestion()
            && $ticketChat->users->contains('id', auth()->id());
        $ticketChatUrl = $canOpenTicketChat
            ? module_url('chat').'?conversacion='.$ticketChat->id
            : null;

        return [
            'id' => $report->id,
            'ticket' => $report->id,
            'date' => $report->created_at?->format('d/m/Y H:i') ?? '—',
            'date_order' => $report->created_at?->toIso8601String(),
            'status' => e($statusLabel),
            'status_label' => $statusLabel,
            'status_class' => $status,
            'status_html' => '<span class="moderacion-status moderacion-status--'.e($status).'">'.e($statusLabel).'</span>',
            'type' => $report->type,
            'type_label' => $typeLabel,
            'type_html' => '<span class="moderacion-type moderacion-type--'.e($report->type).'">'.e($typeLabel).'</span>',
            'module_code' => (string) ($report->module_code ?? ''),
            'module_url' => $moduleUrl,
            'context_label' => (string) ($report->context_label ?? ''),
            'context_url' => (string) ($report->context_url ?? ''),
            'assigned_name' => e($report->assignedAdmin?->name ?? '—'),
            'assigned_raw' => $report->assignedAdmin?->name ?? '—',
            // Columna y filtro «Gestionado por»: administrador original.
            'manager' => e($managerName),
            'manager_raw' => $managerName,
            'manager_id' => $report->taken_by !== null ? (int) $report->taken_by : null,
            // Columna y filtro «Remitido a»: último administrador remitido.
            'referred_to' => e($referredToName),
            'referred_to_raw' => $referredToName,
            'referred_to_id' => $report->referred_to_id !== null ? (int) $report->referred_to_id : null,
            'taken_at' => $report->taken_at?->format('d/m/Y H:i') ?? '—',
            'attention_observation' => (string) ($report->attention_observation ?? ''),
            'closed_at' => $report->closed_at?->format('d/m/Y H:i') ?? '—',
            'closed_by_name' => $report->closedBy?->name ?? '—',
            'close_observation' => (string) ($report->close_observation ?? ''),
            'can_take' => $report->isPending(),
            'can_close' => $report->isAttending() && $isMine,
            'can_reply_suggestion' => $report->isSuggestion() && $report->isAttending() && $isMine,
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
            'ticket_chat_url' => $ticketChatUrl,
            'ticket_chat_id' => $ticketChat?->id,
            'has_ticket_chat' => $ticketChat !== null,
            // Remisión: solo el gestor actual puede remitir el caso.
            'can_refer' => $report->isAttending() && $isMine,
            'referrals' => $referrals,
            'actions' => view('moderacion.partials.actions', [
                'report' => $report,
                'conversationUrl' => $conversationUrl,
                'ticketChatUrl' => $ticketChatUrl,
                'moduleUrl' => $moduleUrl,
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

        if ($conversation->type === 'report') {
            return 'Reporte Nº: '.($conversation->report_id ?? $conversation->id);
        }

        if ($conversation->isGroup()) {
            return 'Grupo: '.($conversation->name ?: 'Sin nombre');
        }

        $names = $conversation->users->pluck('name')->filter()->implode(' / ');

        return $names !== '' ? 'Directa: '.$names : 'Conversación directa';
    }
}
