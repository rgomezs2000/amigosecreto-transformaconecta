<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResuelveEventoConsulta;
use App\Models\Assignment;
use App\Models\Event;
use App\Models\Module;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «Usuarios asociados»: monitoreo de solo lectura de las parejas del evento.
 *
 * Lista, por evento, cada usuario con el amigo secreto que le fue asignado.
 * No abre fichas ni modifica nada: es la vista rápida de quién regala a quién.
 * La página sirve todos los eventos (también los históricos) y por defecto
 * consulta el actual y en curso.
 */
class UsuariosAsociadosController extends Controller
{
    use ResuelveEventoConsulta;

    /**
     * Solo la administración y solo si tiene el módulo concedido: la pareja
     * de cada usuario es información que no se le muestra a los participantes.
     */
    private function ensureAccess(): void
    {
        $module = Module::activeByCode('usuarios-asociados');

        if ($module === null) {
            abort(404);
        }

        $user = auth()->user();

        if (! $user?->hasModuleAccess($module)) {
            abort(403);
        }

        if (! $user->isAdmin() && ! $user->isSuperAdmin()) {
            abort(403, 'El módulo de usuarios asociados es solo para la administración.');
        }
    }

    public function index(): View
    {
        $this->ensureAccess();

        $events = Event::query()->orderByDesc('year')->get();

        return view('usuarios-asociados.index', [
            'events' => $events,
            'event' => $this->eventoActual($events),
        ]);
    }

    /**
     * DataTable paginado con las parejas del evento elegido.
     */
    public function datos(Request $request): JsonResponse
    {
        $this->ensureAccess();

        $draw = (int) $request->input('draw', 1);
        $event = $this->eventoSeleccionado($request);

        if ($event === null) {
            return response()->json([
                'draw' => $draw,
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
                'aviso_html' => '',
            ]);
        }

        $query = Assignment::query()
            ->where('assignments.event_id', $event->id)
            ->join('users as givers', 'givers.id', '=', 'assignments.giver_id')
            ->join('users as receivers', 'receivers.id', '=', 'assignments.receiver_id')
            ->select('assignments.*')
            ->with(['giver', 'receiver']);

        $recordsTotal = (clone $query)->count();

        $search = trim((string) $request->input('search.value', ''));

        if ($search !== '') {
            $like = '%'.$search.'%';

            $query->where(function (Builder $inner) use ($like) {
                $inner->where('givers.name', 'like', $like)
                    ->orWhere('givers.username', 'like', $like)
                    ->orWhere('givers.email', 'like', $like)
                    ->orWhere('receivers.name', 'like', $like)
                    ->orWhere('receivers.username', 'like', $like);
            });
        }

        $recordsFiltered = (clone $query)->count();

        $columnIndex = (int) $request->input('order.0.column', 1);
        $direction = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';

        $column = match ($columnIndex) {
            2 => 'givers.username',
            3 => 'receivers.name',
            default => 'givers.name',
        };

        // Desempate por id: sin él, con nombres repetidos MySQL no garantiza
        // un orden estable y al paginar una fila puede repetirse o saltarse.
        $query->orderBy($column, $direction)->orderBy('assignments.id');

        $start = max(0, (int) $request->input('start', 0));
        $length = (int) $request->input('length', 10);

        if ($length > 0) {
            $query->skip($start)->take($length);
        }

        $data = $query->get()->map(fn (Assignment $assignment) => [
            'id' => (int) $assignment->giver_id,
            'nombre' => e($assignment->giver?->name ?? '—'),
            'usuario' => e($assignment->giver?->username ?? '—'),
            'amigo' => e($assignment->receiver?->name ?? '—'),
        ]);

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
            'aviso_html' => view('usuarios-asociados.partials.aviso', ['event' => $event])->render(),
        ]);
    }
}
