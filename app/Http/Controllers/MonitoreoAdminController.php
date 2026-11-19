<?php

namespace App\Http\Controllers;

use App\Enums\ParticipantStatus;
use App\Http\Controllers\Concerns\ResuelveEstadoEvento;
use App\Http\Controllers\Concerns\ResuelveEventoConsulta;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Module;
use App\Services\InvitacionesEvento;
use App\Support\FaseEvento;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Módulos de consulta de la administración: Lista de deseos, Usuarios
 * obsequiados y Pistas.
 *
 * Los tres comparten la misma página: estado del evento, selector de evento,
 * búsqueda, filtro por estatus de participación y una tabla paginada de
 * usuarios. Cada fila abre una ficha en modal con pestañas según el módulo:
 *
 *  - Lista de deseos: participante + lista de deseos.
 *  - Usuarios obsequiados: participante + obsequio + consumibles.
 *  - Pistas: participante + pistas recibidas + pistas enviadas.
 *
 * Los datos de las pestañas los sirven los endpoints de Participantes, que
 * comprueban el rol de administración y alguno de los módulos de monitoreo.
 */
class MonitoreoAdminController extends Controller
{
    use ResuelveEstadoEvento;
    use ResuelveEventoConsulta;

    /** @var array<string, string> */
    private const MODULOS = [
        'deseos' => 'lista-de-deseos',
        'obsequios' => 'usuarios-obsequiados',
        'pistas' => 'pistas',
    ];

    public function listaDeseos(Request $request): View
    {
        return $this->pagina($request, 'deseos');
    }

    public function obsequiados(Request $request): View
    {
        return $this->pagina($request, 'obsequios');
    }

    public function pistas(Request $request): View
    {
        return $this->pagina($request, 'pistas');
    }

    /**
     * DataTable de usuarios del evento con la búsqueda y el filtro de estatus.
     */
    public function datos(Request $request, string $tipo): JsonResponse
    {
        $tipo = $this->normalizar($tipo);
        $this->ensureAccess($tipo);

        $draw = (int) $request->input('draw', 1);
        $event = $this->eventoSeleccionado($request);
        $estado = $this->estadoEvento($event);

        if ($event === null) {
            return response()->json([
                'draw' => $draw,
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
                'habilitado' => false,
                'estado' => $estado,
                'estado_html' => view('participantes.partials.estado', ['estado' => $estado])->render(),
            ]);
        }

        // Igual que en Participantes: en la Unión se generan las invitaciones
        // que falten para que ningún usuario quede fuera de la lista.
        if ($event->status->allowsInvitations()) {
            app(InvitacionesEvento::class)->generar($event);
        }

        $query = EventParticipant::query()
            ->where('event_participants.event_id', $event->id)
            ->join('users', 'users.id', '=', 'event_participants.user_id')
            ->whereNull('users.deleted_at')
            ->select('event_participants.*')
            ->with('user');

        $recordsTotal = (clone $query)->count();

        $search = trim((string) $request->input('search.value', ''));

        if ($search !== '') {
            $like = '%'.$search.'%';

            $query->where(function (Builder $inner) use ($like) {
                $inner->where('users.name', 'like', $like)
                    ->orWhere('users.email', 'like', $like)
                    ->orWhere('users.username', 'like', $like);
            });
        }

        $statusFilter = (string) $request->input('estado', '');
        $validStatuses = array_map(fn (ParticipantStatus $status) => $status->value, ParticipantStatus::cases());

        if (in_array($statusFilter, $validStatuses, true)) {
            $query->where('event_participants.status', $statusFilter);
        }

        $recordsFiltered = (clone $query)->count();

        $columnIndex = (int) $request->input('order.0.column', 1);
        $direction = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';

        $column = match ($columnIndex) {
            2 => 'users.username',
            3 => 'event_participants.status',
            default => 'users.name',
        };

        $query->orderBy($column, $direction)->orderBy('event_participants.id');

        $start = max(0, (int) $request->input('start', 0));
        $length = (int) $request->input('length', 10);

        if ($length > 0) {
            $query->skip($start)->take($length);
        }

        $data = $query->get()->map(function (EventParticipant $participation) {
            $user = $participation->user;

            return [
                'id' => (int) $user->id,
                'name' => e($user->name),
                'username' => e($user->username ?: '—'),
                'status' => view('participantes.partials.estado-participacion', [
                    'participation' => $participation,
                    'user' => $user,
                ])->render(),
                'status_label' => $participation->status->label(),
            ];
        });

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
            'habilitado' => $this->habilitado($tipo, $event),
            'estado' => $estado,
            'estado_html' => view('participantes.partials.estado', ['estado' => $estado])->render(),
        ]);
    }

    private function pagina(Request $request, string $tipo): View
    {
        $this->ensureAccess($tipo);

        $events = Event::query()->orderByDesc('year')->get();
        $event = $this->eventoActual($events);
        $estado = $this->estadoEvento($event);
        $habilitado = $event !== null && $this->habilitado($tipo, $event);

        return view('monitoreo.index', [
            'tipo' => $tipo,
            'events' => $events,
            'event' => $event,
            'estado' => $estado,
            'habilitado' => $habilitado,
            'titulo' => $this->titulo($tipo),
            'descripcion' => $this->descripcion($tipo),
            'aviso' => $this->aviso($tipo, $estado),
            'etiquetaAccion' => $this->etiquetaAccion($tipo),
        ]);
    }

    private function normalizar(string $tipo): string
    {
        if (! array_key_exists($tipo, self::MODULOS)) {
            abort(404);
        }

        return $tipo;
    }

    private function ensureAccess(string $tipo): void
    {
        $module = Module::activeByCode(self::MODULOS[$tipo]);

        if ($module === null) {
            abort(404);
        }

        $user = auth()->user();

        if (! $user?->hasModuleAccess($module)) {
            abort(403);
        }

        if (! $user->isAdmin() && ! $user->isSuperAdmin()) {
            abort(403, 'Estos módulos son solo para la administración.');
        }
    }

    /**
     * El módulo se habilita con la fase del flujo:
     *  - Lista de deseos: desde la Unión.
     *  - Obsequios y pistas: desde el Intercambio de pistas (tras el sorteo).
     */
    private function habilitado(string $tipo, Event $event): bool
    {
        $paso = FaseEvento::paso($event);

        return $tipo === 'deseos' ? $paso >= 2 : $paso >= 4;
    }

    private function titulo(string $tipo): string
    {
        return match ($tipo) {
            'obsequios' => 'Usuarios obsequiados',
            'pistas' => 'Pistas',
            default => 'Lista de deseos',
        };
    }

    private function descripcion(string $tipo): string
    {
        return match ($tipo) {
            'obsequios' => 'Obsequios que reciben los usuarios de sus amigos secretos. Solo lectura.',
            'pistas' => 'Pistas que intercambian los usuarios con su amigo secreto. Solo lectura.',
            default => 'Listas de deseos que los usuarios comparten con su amigo secreto. Solo lectura.',
        };
    }

    private function etiquetaAccion(string $tipo): string
    {
        return match ($tipo) {
            'obsequios' => 'Ver obsequios',
            'pistas' => 'Ver pistas',
            default => 'Ver lista de deseos',
        };
    }

    /**
     * @param  array<string, mixed>  $estado
     */
    private function aviso(string $tipo, array $estado): string
    {
        if ($tipo === 'deseos') {
            return 'La consulta de listas de deseos se activa en la fase de Unión.';
        }

        if ($estado['fase'] < 4) {
            return $tipo === 'pistas'
                ? 'Las pistas se activan después del sorteo ciego, en la fase de Intercambio de pistas.'
                : 'Los obsequios se activan después del sorteo ciego, en la fase de Intercambio de pistas.';
        }

        return '';
    }
}
