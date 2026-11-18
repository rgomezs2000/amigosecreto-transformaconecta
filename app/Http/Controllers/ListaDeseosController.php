<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Module;
use App\Models\WishItem;
use App\Support\FaseEvento;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Lista de deseos del participante.
 *
 * Se activa en la fase Unión en cuanto el usuario acepta la invitación y queda
 * activo. Mientras el sorteo no concluya, la lista es editable; después queda
 * en solo lectura. El amigo secreto la consulta desde «Ver tu amigo» para
 * elegir el obsequio.
 */
class ListaDeseosController extends Controller
{
    public function __construct() {}

    public function index(): View
    {
        $this->ensureAccess();

        $event = $this->eventoActual();
        $user = request()->user();
        $participacion = $event !== null ? $this->participacion($event, $user->id) : null;
        $editable = $this->puedeEditar($event, $participacion);

        return view('lista-deseos.index', [
            'event' => $event,
            'participacion' => $participacion,
            'editable' => $editable,
            'total' => $event !== null && $participacion !== null
                ? WishItem::query()->where('event_id', $event->id)->where('user_id', $user->id)->count()
                : 0,
        ]);
    }

    /**
     * DataTable paginado de los deseos del usuario.
     */
    public function datos(Request $request): JsonResponse
    {
        $this->ensureAccess();

        $event = $this->eventoActual();

        if ($event === null) {
            return response()->json([
                'draw' => (int) $request->input('draw', 1),
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
                'editable' => false,
            ]);
        }

        $participacion = $this->participacion($event, $request->user()->id);

        if ($participacion === null) {
            return response()->json([
                'draw' => (int) $request->input('draw', 1),
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
                'editable' => false,
            ]);
        }

        $draw = (int) $request->input('draw', 1);
        $editable = $this->puedeEditar($event, $participacion);

        $query = WishItem::query()
            ->where('event_id', $event->id)
            ->where('user_id', $request->user()->id);

        $recordsTotal = (clone $query)->count();
        $search = trim((string) $request->input('search.value', ''));

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $inner) use ($like) {
                $inner->where('title', 'like', $like)
                    ->orWhere('description', 'like', $like);
            });
        }

        $recordsFiltered = (clone $query)->count();
        $columnIndex = (int) $request->input('order.0.column', 0);
        $direction = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';

        $column = match ($columnIndex) {
            1 => 'title',
            3 => 'created_at',
            default => 'id',
        };

        $query->orderBy($column, $direction)->orderBy('id');

        $start = max(0, (int) $request->input('start', 0));
        $length = (int) $request->input('length', 10);

        if ($length > 0) {
            $query->skip($start)->take($length);
        }

        $data = $query->get()->map(fn (WishItem $item) => [
            'id' => $item->id,
            'deseo' => e($item->title),
            'descripcion' => e($item->description ?: '—'),
            'fecha' => format_date($item->created_at, 'd/m/Y H:i'),
            'actions' => view('lista-deseos.partials.actions', [
                'item' => $item,
                'editable' => $editable,
            ])->render(),
        ]);

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
            'editable' => $editable,
        ]);
    }

    public function crear(Request $request): JsonResponse
    {
        $this->ensureAccess();

        [$event, $participacion, $error] = $this->contextoEditable($request);

        if ($error !== null) {
            return $error;
        }

        $validator = $this->validator($request);

        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $data = $validator->validated();

        $item = WishItem::create([
            'event_id' => $event->id,
            'user_id' => $request->user()->id,
            'title' => trim((string) $data['title']),
            'description' => $this->textoOpcional($data['description'] ?? null),
            'url' => $this->textoOpcional($data['url'] ?? null),
        ]);

        return api_success('Deseo agregado a tu lista.', [
            'item' => $this->itemData($item),
        ]);
    }

    public function modificar(Request $request, WishItem $item): JsonResponse
    {
        $this->ensureAccess();

        [$event, $participacion, $error] = $this->contextoEditable($request);

        if ($error !== null) {
            return $error;
        }

        $this->propio($item, $event, $request->user()->id);

        $validator = $this->validator($request);

        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $data = $validator->validated();

        $item->update([
            'title' => trim((string) $data['title']),
            'description' => $this->textoOpcional($data['description'] ?? null),
            'url' => $this->textoOpcional($data['url'] ?? null),
        ]);

        return api_success('Deseo modificado correctamente.', [
            'item' => $this->itemData($item->fresh()),
        ]);
    }

    /**
     * Ficha del deseo para el modal «Ver».
     */
    public function ver(Request $request, WishItem $item): JsonResponse
    {
        $this->ensureAccess();

        $event = $this->eventoActual();

        if ($event === null) {
            return api_error('Todavía no hay un evento activo.', 409);
        }

        $this->propio($item, $event, $request->user()->id);

        return api_success('Deseo.', [
            'item' => $this->itemData($item),
            'html' => view('lista-deseos.partials.ficha', ['item' => $item])->render(),
        ]);
    }

    public function eliminar(Request $request, WishItem $item): JsonResponse
    {
        $this->ensureAccess();

        [$event, $participacion, $error] = $this->contextoEditable($request);

        if ($error !== null) {
            return $error;
        }

        $this->propio($item, $event, $request->user()->id);
        $item->delete();

        return api_success('Deseo eliminado correctamente.');
    }

    /**
     * @return array{0: Event, 1: EventParticipant, 2: JsonResponse|null}
     */
    private function contextoEditable(Request $request): array
    {
        $event = $this->eventoActual();

        if ($event === null) {
            return [new Event, new EventParticipant, api_error('Todavía no hay un evento activo.', 409)];
        }

        $participacion = $this->participacion($event, $request->user()->id);

        if ($participacion === null || ! $participacion->status->isParticipating()) {
            return [new Event, new EventParticipant, api_error('Primero acepta la invitación al evento.', 409)];
        }

        if (! $this->puedeEditar($event, $participacion)) {
            return [new Event, new EventParticipant, api_error(
                'La lista de deseos quedó en solo lectura porque el sorteo ya se realizó.',
                409
            )];
        }

        return [$event, $participacion, null];
    }

    private function validator(Request $request): \Illuminate\Validation\Validator
    {
        return Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'url' => ['nullable', 'url', 'max:255'],
        ], [
            'title.required' => 'El deseo es obligatorio.',
            'title.max' => 'El deseo no puede superar los 150 caracteres.',
            'description.max' => 'La descripción no puede superar los 500 caracteres.',
            'url.url' => 'Escribe un enlace válido.',
            'url.max' => 'El enlace es demasiado largo.',
        ]);
    }

    private function puedeEditar(?Event $event, ?EventParticipant $participacion): bool
    {
        if ($event === null || $participacion === null || ! $participacion->status->isParticipating()) {
            return false;
        }

        return in_array($event->status, [
            \App\Enums\EventStatus::Registration,
            \App\Enums\EventStatus::DrawEnabled,
        ], true);
    }

    private function participacion(Event $event, int $userId): ?EventParticipant
    {
        return EventParticipant::query()
            ->where('event_id', $event->id)
            ->where('user_id', $userId)
            ->first();
    }

    private function propio(WishItem $item, Event $event, int $userId): void
    {
        if ((int) $item->event_id !== (int) $event->id || (int) $item->user_id !== (int) $userId) {
            abort(404, 'El deseo no pertenece a tu lista.');
        }
    }

    private function textoOpcional(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function itemData(WishItem $item): array
    {
        return [
            'id' => $item->id,
            'title' => (string) $item->title,
            'description' => (string) ($item->description ?? ''),
            'url' => (string) ($item->url ?? ''),
            'created_at' => format_date($item->created_at, 'd/m/Y H:i'),
        ];
    }

    private function errorValidacion(\Illuminate\Validation\Validator $validator): JsonResponse
    {
        return api_response(false, (string) $validator->errors()->first(), [
            'errors' => $validator->errors(),
        ], 422);
    }

    private function eventoActual(): ?Event
    {
        $event = Event::query()->orderByDesc('year')->first();

        return FaseEvento::sincronizar($event);
    }

    private function ensureAccess(): void
    {
        $module = Module::activeByCode('lista-de-deseos');

        if ($module === null) {
            abort(404);
        }

        if (! auth()->user()?->hasModuleAccess($module)) {
            abort(403);
        }
    }
}
