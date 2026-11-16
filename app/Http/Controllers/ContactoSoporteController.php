<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\ChatReport;
use App\Models\ChatReportAttachment;
use App\Models\ChatReportRating;
use App\Models\Module;
use App\Services\Firestore\FirestoreService;
use App\Services\PushNotifier;
use App\Services\ReporteAdjuntos;
use App\Services\ReporteAvisos;
use App\Services\ReporteChat;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Contacto y soporte para el usuario: crear un caso y hacerle seguimiento.
 *
 * El usuario puede crear casos de moderación, sugerencia, ayuda o soporte.
 * El chat del caso nace en solo lectura y el administrador entra al tomarlo;
 * las sugerencias no abren chat y se responden desde su pestaña.
 */
class ContactoSoporteController extends Controller
{
    public function __construct(
        private readonly ReporteAdjuntos $adjuntos,
        private readonly ReporteChat $reportesChat,
        private readonly ReporteAvisos $avisos,
        private readonly PushNotifier $push,
        private readonly FirestoreService $firestore,
    ) {}

    public function index(): View
    {
        $this->ensureAccess();

        return view('contacto-soporte.index', [
            'types' => $this->tipos(),
            'aspects' => ChatReportRating::ASPECTS,
            'labels' => ChatReportRating::LABELS,
        ]);
    }

    /**
     * Tipos disponibles en el formulario del usuario.
     *
     * @return array<string, string>
     */
    private function tipos(): array
    {
        return [
            ChatReport::TYPE_MODERATION => 'Moderación y contenido',
            ChatReport::TYPE_SUGGESTION => 'Sugerencia',
            ChatReport::TYPE_HELP => 'Ayuda',
            ChatReport::TYPE_SUPPORT => 'Soporte',
        ];
    }

    /**
     * Tabla de seguimiento del usuario (DataTable en servidor).
     */
    public function datos(Request $request): JsonResponse
    {
        $this->ensureAccess();

        $user = $request->user();
        $draw = (int) $request->input('draw', 1);
        $start = max(0, (int) $request->input('start', 0));
        $length = min(100, max(1, (int) $request->input('length', 10)));
        $search = trim((string) $request->input('search.value', ''));

        $base = ChatReport::query()->where('reporter_id', $user->id);
        $recordsTotal = (clone $base)->count();

        $filtered = clone $base;

        if ($search !== '') {
            $like = '%'.$search.'%';
            $filtered->where(function ($query) use ($like, $search) {
                $query->where('reason', 'like', $like)
                    ->orWhere('type', 'like', $like)
                    ->orWhere('case_message', 'like', $like)
                    ->orWhere('suggestion', 'like', $like);

                if (ctype_digit($search)) {
                    $query->orWhereKey((int) $search);
                }
            });
        }

        $filterType = (string) $request->input('filter_type', 'all');

        if ($filterType === 'status') {
            $status = (string) $request->input('status', '');

            if (in_array($status, [
                ChatReport::STATUS_PENDING,
                ChatReport::STATUS_ATTENDING,
                ChatReport::STATUS_CLOSED,
            ], true)) {
                $filtered->where('status', $status);
            }
        }

        if ($filterType === 'case_type') {
            $type = (string) $request->input('case_type', '');

            if (array_key_exists($type, ChatReport::TYPES)) {
                $filtered->where('type', $type);
            }
        }

        if ($filterType === 'date') {
            $from = (string) $request->input('date_from', '');
            $to = (string) $request->input('date_to', '');

            if ($from !== '') {
                $filtered->whereDate('created_at', '>=', $from);
            }

            if ($to !== '') {
                $filtered->whereDate('created_at', '<=', $to);
            }
        }

        $recordsFiltered = (clone $filtered)->count();

        $reports = $filtered
            ->orderByDesc('id')
            ->skip($start)
            ->take($length)
            ->get();

        $data = $reports->map(fn (ChatReport $report) => [
            'id' => (int) $report->id,
            'ticket' => $report->id,
            'type' => $report->type,
            'type_label' => $report->typeLabel(),
            'type_html' => '<span class="moderacion-type moderacion-type--'.e($report->type).'">'.e($report->typeLabel()).'</span>',
            'reason' => e(Str::limit((string) $report->reason, 100)),
            'reason_full' => (string) $report->reason,
            'status_label' => $report->statusLabel(),
            'status_class' => $report->status,
            'status_html' => '<span class="moderacion-status moderacion-status--'.e($report->status).'">'.e($report->statusLabel()).'</span>',
            'actions' => view('contacto-soporte.partials.actions', ['report' => $report])->render(),
        ])->values();

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }

    /**
     * Crea un caso desde el formulario del usuario.
     */
    public function crear(Request $request): JsonResponse
    {
        $this->ensureAccess();

        $validator = $this->validarCaso($request);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), ['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $report = ChatReport::create([
            'type' => $data['type'],
            'reporter_id' => $request->user()->id,
            'reason' => $data['reason'],
            'case_message' => $data['message'],
            'suggestion' => $data['type'] === ChatReport::TYPE_SUGGESTION
                ? ($data['suggestion'] ?? null)
                : null,
            'context_url' => $data['context_url'] ?? null,
            'context_label' => $data['context_label'] ?? null,
        ]);

        $this->adjuntos->guardarArchivos($report, $request->file('files', []));
        $this->adjuntos->guardarEnlaces($report, $request->input('links', []));

        if ($report->isModeration()) {
            $this->adjuntos->generarEvidencia($report);
        }

        $this->guardarRatings($report, $data['ratings'] ?? []);

        $conversation = $this->reportesChat->abrirTicket($report);
        $this->avisos->abierto($report, $conversation);

        $this->push->sendToAdmins(
            'chat.reporte',
            'Nuevo caso Nº '.$report->id,
            $request->user()->name.' creó un caso de '.$report->typeLabel().'.',
            [
                'report_id' => $report->id,
                'conversation_id' => $conversation?->id,
                'reporter' => $request->user()->name,
                'reason' => $report->reason,
                'case_type' => $report->type,
            ],
            module_url('moderacion'),
            'reporte',
            $request->user()
        );

        return api_success('Caso creado correctamente.', [
            'ticket' => $report->id,
            'report' => [
                'id' => (int) $report->id,
                'type' => $report->type,
                'type_label' => $report->typeLabel(),
                'status' => $report->status,
                'status_label' => $report->statusLabel(),
            ],
            'conversation_id' => $conversation?->id,
        ]);
    }

    /**
     * Detalle para el modal de seguimiento del usuario.
     */
    public function detalle(Request $request, ChatReport $report): JsonResponse
    {
        $this->ensureAccess();
        $this->ensureOwnReport($request, $report);

        $report->load([
            'message',
            'conversation.messages.user',
            'attachments',
            'links',
            'ratings',
        ]);

        $attachments = $report->attachments->map(fn (ChatReportAttachment $attachment) => [
            'id' => (int) $attachment->id,
            'kind' => $attachment->kind,
            'name' => $attachment->name,
            'mime' => (string) $attachment->mime,
            'size_label' => $attachment->humanSize(),
            'url' => route('contacto-soporte.adjuntos', ['attachment' => $attachment->id]),
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

        $conversation = $report->conversation;
        $messages = [];

        if ($conversation !== null && ! $report->isSuggestion()) {
            $messages = $conversation->messages()
                ->with('user')
                ->orderBy('id')
                ->get()
                ->map(fn (ChatMessage $message) => [
                    'id' => (int) $message->id,
                    'body' => (string) $message->body,
                    'author' => $message->user?->name ?? 'Sistema',
                    'is_admin' => (bool) $message->user?->isAdmin(),
                    'date' => $message->created_at?->format('d/m/Y H:i') ?? '—',
                ])
                ->values()
                ->all();
        }

        // En la vista del usuario la sugerencia muestra solo la valoración
        // cualitativa; los puntos y promedios son de la ficha administrativa.
        $ratings = $report->isSuggestion()
            ? $report->ratings->map(fn (ChatReportRating $rating) => [
                'aspect' => $rating->aspect,
                'aspect_label' => $rating->aspectLabel(),
                'qualitative' => $rating->qualitativeLabel(),
            ])->values()->all()
            : [];

        return api_success('Detalle del caso.', [
            'report' => [
                'id' => (int) $report->id,
                'ticket' => $report->id,
                'type' => $report->type,
                'type_label' => $report->typeLabel(),
                'status' => $report->status,
                'status_label' => $report->statusLabel(),
                'reason' => (string) $report->reason,
                'message' => (string) (($report->case_message ?? '') ?: ($report->message?->body ?? '')),
                'suggestion' => (string) ($report->suggestion ?? ''),
                'date' => $report->created_at?->format('d/m/Y H:i') ?? '—',
            ],
            'attachments' => [
                'media' => $media,
                'audios' => $audios,
                'documents' => $documents,
            ],
            'links' => $links,
            'ratings' => $ratings,
            'chat' => [
                'conversation_id' => $conversation?->id,
                'read_only' => $conversation?->is_read_only ?? true,
                'messages' => $messages,
            ],
        ]);
    }

    /**
     * Mensaje del usuario dentro del chat del caso, desde el seguimiento.
     */
    public function enviarMensaje(Request $request, ChatReport $report): JsonResponse
    {
        $this->ensureAccess();
        $this->ensureOwnReport($request, $report);

        if ($report->isSuggestion()) {
            return api_error('Las sugerencias no tienen chat. La respuesta se lee en la pestaña Sugerencia.', 422);
        }

        $conversation = $report->conversation;

        if ($conversation === null) {
            return api_error('El caso todavía no tiene chat.', 422);
        }

        if ($conversation->is_read_only) {
            return api_error('El chat está en solo lectura hasta que un administrador tome el caso.', 422);
        }

        $validator = Validator::make($request->all(), [
            'body' => ['required', 'string', 'max:4000'],
        ], [
            'body.required' => 'Escribe un mensaje.',
            'body.max' => 'El mensaje no puede pasar de 4000 caracteres.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), ['errors' => $validator->errors()], 422);
        }

        $message = $conversation->messages()->create([
            'user_id' => $request->user()->id,
            'body' => $validator->validated()['body'],
            'type' => 'text',
            'is_moderation' => false,
            'delivered_at' => now(),
        ]);

        $conversation->touch();

        $this->firestore->recordMessage(
            $message->load('user', 'attachments', 'conversation')
        );

        return api_success('Mensaje enviado.', [
            'message' => [
                'id' => (int) $message->id,
                'body' => (string) $message->body,
                'author' => $request->user()->name,
                'is_admin' => $request->user()->isAdmin(),
                'date' => $message->created_at?->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i'),
            ],
        ]);
    }

    /**
     * Archivo adjunto del caso para su beneficiario.
     */
    public function adjunto(Request $request, ChatReportAttachment $attachment): BinaryFileResponse
    {
        $this->ensureAccess();

        if ((int) $attachment->report?->reporter_id !== (int) $request->user()->id) {
            abort(403);
        }

        $path = Storage::disk('local')->path($attachment->path);

        if (! is_file($path)) {
            abort(404);
        }

        $headers = ['Content-Type' => $attachment->mime ?: 'application/octet-stream'];

        if ($attachment->isImage() || $attachment->isVideo() || $attachment->isAudio()) {
            return response()->file($path, $headers);
        }

        return response()->download($path, $attachment->name, $headers);
    }

    /**
     * Reglas compartidas por el formulario del usuario y el de administración.
     */
    private function validarCaso(Request $request): \Illuminate\Validation\Validator
    {
        $validator = Validator::make($request->all(), [
            'type' => ['required', 'string', 'in:'.implode(',', array_keys($this->tipos()))],
            'reason' => ['required', 'string', 'max:2000'],
            'message' => ['required', 'string', 'max:5000'],
            'suggestion' => ['nullable', 'string', 'max:5000'],
            'ratings' => ['nullable', 'array'],
            'ratings.*' => ['integer', 'between:1,5'],
            'links' => ['nullable', 'array', 'max:20'],
            'links.*' => ['nullable', 'string', 'max:2000', 'url'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'max:51200'],
            'context_url' => ['nullable', 'string', 'max:500'],
            'context_label' => ['nullable', 'string', 'max:160'],
        ], [
            'type.required' => 'Selecciona el tipo de reporte.',
            'type.in' => 'El tipo de reporte no es válido.',
            'reason.required' => 'Escribe el motivo del reporte.',
            'reason.max' => 'El motivo no puede pasar de 2000 caracteres.',
            'message.required' => 'Escribe el mensaje detallado del reporte.',
            'message.max' => 'El mensaje no puede pasar de 5000 caracteres.',
            'suggestion.max' => 'La sugerencia no puede pasar de 5000 caracteres.',
            'links.*.url' => 'Cada enlace debe ser una dirección válida.',
            'files.*.max' => 'Cada archivo puede pesar hasta 50 MB.',
        ]);

        $validator->after(function ($validator) use ($request) {
            if ($request->input('type') !== ChatReport::TYPE_SUGGESTION) {
                return;
            }

            $suggestion = trim((string) $request->input('suggestion', ''));

            if ($suggestion === '') {
                $validator->errors()->add('suggestion', 'Escribe el texto de la sugerencia.');
            }

            $ratings = $request->input('ratings', []);

            foreach (array_keys(ChatReportRating::ASPECTS) as $aspect) {
                $score = $ratings[$aspect] ?? null;

                if (! is_numeric($score) || (int) $score < 1 || (int) $score > 5) {
                    $validator->errors()->add(
                        'ratings.'.$aspect,
                        'Valora el aspecto '.ChatReportRating::ASPECTS[$aspect].'.'
                    );
                }
            }
        });

        return $validator;
    }

    /**
     * @param  array<string, mixed>  $ratings
     */
    private function guardarRatings(ChatReport $report, array $ratings): void
    {
        if (! $report->isSuggestion()) {
            return;
        }

        foreach (array_keys(ChatReportRating::ASPECTS) as $aspect) {
            $score = (int) ($ratings[$aspect] ?? 0);

            if ($score >= 1 && $score <= 5) {
                $report->ratings()->create([
                    'aspect' => $aspect,
                    'score' => $score,
                ]);
            }
        }
    }

    private function ensureAccess(): Module
    {
        $module = Module::activeByCode('contacto-soporte');

        if ($module === null) {
            abort(404);
        }

        if (! auth()->user()?->hasModuleAccess($module)) {
            abort(403);
        }

        return $module;
    }

    private function ensureOwnReport(Request $request, ChatReport $report): void
    {
        if ((int) $report->reporter_id !== (int) $request->user()->id) {
            abort(403);
        }
    }
}
