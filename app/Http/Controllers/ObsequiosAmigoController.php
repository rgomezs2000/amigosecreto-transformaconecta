<?php

namespace App\Http\Controllers;

use App\Enums\EventStatus;
use App\Models\Assignment;
use App\Models\ConsumableGift;
use App\Models\Event;
use App\Models\ExchangeGift;
use App\Models\Module;
use App\Services\SorteoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Obsequios del «Ver tu amigo»: el regalo único y los consumibles que el
 * participante registra para su amigo secreto.
 *
 * Estas acciones se activan cuando el sorteo ya concluyó y el evento está en
 * la etapa de obsequios (estado «sorteado»). Mientras el evento esté cerrado o
 * revelado la información queda en solo lectura; si el sorteo sigue abierto se
 * responde que todavía no está disponible.
 *
 * Todos los montos se guardan en USD. El equivalente en bolívares se calcula
 * con la tasa oficial del BCV y se devuelve solo para mostrarlo.
 */
class ObsequiosAmigoController extends Controller
{
    public function __construct(private readonly SorteoService $sorteo) {}

    private function ensureAccess(): void
    {
        $module = Module::activeByCode('ver-tu-amigo');

        if ($module === null) {
            abort(404);
        }

        if (! auth()->user()?->hasModuleAccess($module)) {
            abort(403);
        }
    }

    /**
     * Conversión de un monto en US$ a bolívares con la tasa oficial (AJAX).
     */
    public function tasa(Request $request): JsonResponse
    {
        $this->ensureAccess();

        $valor = $this->monto($request->input('valor'));
        $tasa = dollar_rate();
        $bs = $tasa !== null ? $valor * $tasa : null;

        return api_success('Tasa oficial BCV.', [
            'tasa' => $tasa,
            'tasa_formateada' => $tasa !== null ? format_rate($tasa) : null,
            'valor_usd' => $valor,
            'valor_usd_formateado' => $this->usd($valor),
            'valor_bs' => $bs,
            'valor_bs_formateado' => $bs !== null ? $this->bs($bs) : null,
            'disponible' => $tasa !== null,
        ]);
    }

    /**
     * Registra el obsequio único. Solo se permite mientras no exista uno.
     */
    public function guardar(Request $request): JsonResponse
    {
        $this->ensureAccess();

        $contexto = $this->contexto($request);

        if ($contexto instanceof JsonResponse) {
            return $contexto;
        }

        [$event, $assignment, $editable] = $contexto;

        if (! $editable) {
            return api_error($this->mensajeSoloLectura($event), 409);
        }

        $this->normalizarValor($request);

        $validator = Validator::make($request->all(), $this->reglas(), $this->mensajes());

        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $data = $validator->validated();
        $valor = (float) $data['valor'];

        if (($error = $this->validarTopeObsequio($event, $valor)) !== null) {
            return $this->errorValor($error);
        }

        if ($assignment->exchangeGift()->exists()) {
            return api_error('Ya registraste un obsequio. Puedes modificarlo.', 409);
        }

        $gift = ExchangeGift::create([
            'assignment_id' => $assignment->id,
            'product' => trim((string) $data['nombre']),
            'description' => null,
            'value' => $valor,
            'registered_at' => now(),
        ]);

        return api_success('Obsequio registrado correctamente.', [
            'gift' => $this->giftData($gift, $event),
        ]);
    }

    /**
     * Modifica el obsequio único ya registrado.
     */
    public function modificar(Request $request): JsonResponse
    {
        $this->ensureAccess();

        $contexto = $this->contexto($request);

        if ($contexto instanceof JsonResponse) {
            return $contexto;
        }

        [$event, $assignment, $editable] = $contexto;

        if (! $editable) {
            return api_error($this->mensajeSoloLectura($event), 409);
        }

        $gift = $assignment->exchangeGift;

        if ($gift === null) {
            return api_error('Todavía no has registrado un obsequio.', 409);
        }

        $this->normalizarValor($request);

        $validator = Validator::make($request->all(), $this->reglas(), $this->mensajes());

        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $data = $validator->validated();
        $valor = (float) $data['valor'];

        if (($error = $this->validarTopeObsequio($event, $valor)) !== null) {
            return $this->errorValor($error);
        }

        $gift->update([
            'product' => trim((string) $data['nombre']),
            'value' => $valor,
        ]);

        return api_success('Obsequio modificado correctamente.', [
            'gift' => $this->giftData($gift->fresh(), $event),
        ]);
    }

    /**
     * DataTable paginado de los obsequios consumibles del participante.
     */
    public function consumibles(Request $request): JsonResponse
    {
        $this->ensureAccess();

        $contexto = $this->contexto($request);

        if ($contexto instanceof JsonResponse) {
            return $contexto;
        }

        [$event, $assignment, $editable] = $contexto;

        $draw = (int) $request->input('draw', 1);

        $query = ConsumableGift::query()->where('assignment_id', $assignment->id);
        $recordsTotal = (clone $query)->count();

        $search = trim((string) $request->input('search.value', ''));

        if ($search !== '') {
            $query->where('description', 'like', '%'.$search.'%');
        }

        $recordsFiltered = (clone $query)->count();

        $columnIndex = (int) $request->input('order.0.column', 0);
        $direction = $request->input('order.0.dir') === 'asc' ? 'asc' : 'desc';
        $column = match ($columnIndex) {
            1 => 'description',
            2 => 'value',
            default => 'given_on',
        };

        $query->orderBy($column, $direction)->orderByDesc('id');

        $start = max(0, (int) $request->input('start', 0));
        $length = (int) $request->input('length', 10);

        if ($length > 0) {
            $query->skip($start)->take($length);
        }

        $tasa = dollar_rate();

        $data = $query->get()->map(fn (ConsumableGift $gift) => [
            'id' => $gift->id,
            'fecha' => format_date($gift->given_on, 'd/m/Y'),
            'nombre' => e($gift->description),
            'valor_usd' => $this->usd($gift->value),
            'valor_bs' => $this->bsDe($gift->value, $tasa),
            'actions' => view('amigo.partials.consumible-acciones', [
                'consumible' => $gift,
                'puedeEditar' => $editable,
            ])->render(),
        ]);

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
            'puede_editar' => $editable,
            'tasa' => $tasa,
            'tasa_formateada' => $tasa !== null ? format_rate($tasa) : null,
        ]);
    }

    /**
     * Agrega un obsequio consumible.
     */
    public function crearConsumible(Request $request): JsonResponse
    {
        $this->ensureAccess();

        $contexto = $this->contexto($request);

        if ($contexto instanceof JsonResponse) {
            return $contexto;
        }

        [$event, $assignment, $editable] = $contexto;

        if (! $editable) {
            return api_error($this->mensajeSoloLectura($event), 409);
        }

        $this->normalizarValor($request);

        $validator = Validator::make($request->all(), $this->reglas(), $this->mensajes());

        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $data = $validator->validated();
        $valor = (float) $data['valor'];

        if (($error = $this->validarTopeConsumibles($event, $assignment, $valor)) !== null) {
            return $this->errorValor($error);
        }

        $gift = ConsumableGift::create([
            'assignment_id' => $assignment->id,
            'description' => trim((string) $data['nombre']),
            'quantity' => 1,
            'value' => $valor,
            'given_on' => now()->toDateString(),
        ]);

        return api_success('Obsequio consumible agregado correctamente.', [
            'consumible' => $this->consumibleData($gift, $event),
        ]);
    }

    /**
     * Modifica un obsequio consumible propio.
     */
    public function modificarConsumible(Request $request, ConsumableGift $consumable): JsonResponse
    {
        $this->ensureAccess();

        $contexto = $this->contexto($request);

        if ($contexto instanceof JsonResponse) {
            return $contexto;
        }

        [$event, $assignment, $editable] = $contexto;

        if (! $editable) {
            return api_error($this->mensajeSoloLectura($event), 409);
        }

        $this->propio($consumable, $assignment);

        $this->normalizarValor($request);

        $validator = Validator::make($request->all(), $this->reglas(), $this->mensajes());

        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $data = $validator->validated();
        $valor = (float) $data['valor'];

        if (($error = $this->validarTopeConsumibles($event, $assignment, $valor, $consumable->id)) !== null) {
            return $this->errorValor($error);
        }

        $consumable->update([
            'description' => trim((string) $data['nombre']),
            'value' => $valor,
        ]);

        return api_success('Obsequio consumible modificado correctamente.', [
            'consumible' => $this->consumibleData($consumable->fresh(), $event),
        ]);
    }

    /**
     * Ficha de un obsequio consumible para el modal «Ver».
     */
    public function verConsumible(Request $request, ConsumableGift $consumable): JsonResponse
    {
        $this->ensureAccess();

        $contexto = $this->contexto($request);

        if ($contexto instanceof JsonResponse) {
            return $contexto;
        }

        [$event, $assignment, $editable] = $contexto;

        $this->propio($consumable, $assignment);

        return api_success('Obsequio consumible.', [
            'consumible' => $this->consumibleData($consumable, $event),
            'puede_editar' => $editable,
            'html' => view('amigo.partials.consumible-ficha', [
                'consumible' => $consumable,
                'valorUsd' => $this->usd($consumable->value),
                'valorBs' => $this->bsDe($consumable->value, dollar_rate()),
            ])->render(),
        ]);
    }

    /**
     * Elimina un obsequio consumible propio.
     */
    public function eliminarConsumible(Request $request, ConsumableGift $consumable): JsonResponse
    {
        $this->ensureAccess();

        $contexto = $this->contexto($request);

        if ($contexto instanceof JsonResponse) {
            return $contexto;
        }

        [$event, $assignment, $editable] = $contexto;

        if (! $editable) {
            return api_error($this->mensajeSoloLectura($event), 409);
        }

        $this->propio($consumable, $assignment);

        $consumable->delete();

        return api_success('Obsequio consumible eliminado correctamente.');
    }

    /**
     * Evento actual, asignación del usuario y si los obsequios se pueden
     * editar. Cuando no corresponde, devuelve la respuesta JSON con el motivo.
     *
     * @return array{0: Event, 1: Assignment, 2: bool}|JsonResponse
     */
    private function contexto(Request $request): array|JsonResponse
    {
        $event = Event::query()->orderByDesc('year')->first();

        if ($event === null) {
            return api_error('Todavía no hay un evento activo.', 409);
        }

        $assignment = $this->sorteo->asignacionDe($event, $request->user());

        if ($assignment === null) {
            return api_error('Todavía no tienes un amigo secreto asignado.', 409);
        }

        if ($assignment->drawn_at === null) {
            return api_error('Primero descubre a tu amigo secreto.', 409);
        }

        if (! in_array($event->status, [EventStatus::Drawn, EventStatus::Closed, EventStatus::Revealed], true)) {
            return api_error('Los obsequios se habilitan cuando concluya el sorteo.', 409);
        }

        return [$event, $assignment, $event->status === EventStatus::Drawn];
    }

    private function mensajeSoloLectura(Event $event): string
    {
        if ($event->status->isFinished()) {
            return 'El evento ya finalizó: los obsequios quedan en solo lectura.';
        }

        if ($event->isSuspended()) {
            return 'El evento está suspendido: los obsequios quedan en solo lectura.';
        }

        return 'Los obsequios están en solo lectura.';
    }

    private function propio(ConsumableGift $consumable, Assignment $assignment): void
    {
        if ((int) $consumable->assignment_id !== (int) $assignment->id) {
            abort(404, 'El obsequio consumible no pertenece a tu amigo secreto.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function reglas(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:150'],
            'valor' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function mensajes(): array
    {
        return [
            'nombre.required' => 'El nombre es obligatorio.',
            'nombre.string' => 'Escribe un nombre válido.',
            'nombre.max' => 'El nombre no puede superar los 150 caracteres.',
            'valor.required' => 'El valor es obligatorio.',
            'valor.numeric' => 'Escribe un valor válido en US$.',
            'valor.min' => 'El valor debe ser mayor que cero.',
            'valor.max' => 'El valor es demasiado alto.',
        ];
    }

    private function normalizarValor(Request $request): void
    {
        $valor = $request->input('valor');

        if (is_string($valor)) {
            $request->merge(['valor' => str_replace(',', '.', trim($valor))]);
        }
    }

    private function monto(mixed $valor): float
    {
        if (is_string($valor)) {
            $valor = str_replace(',', '.', trim($valor));
        }

        return is_numeric($valor) ? max(0, (float) $valor) : 0.0;
    }

    private function validarTopeObsequio(Event $event, float $valor): ?string
    {
        $min = $event->min_value !== null ? (float) $event->min_value : null;
        $max = $event->max_value !== null ? (float) $event->max_value : null;

        if ($min !== null && $min > 0 && $valor < $min) {
            return 'El valor del obsequio debe ser al menos US$ '.format_rate($min).'.';
        }

        if ($max !== null && $max > 0 && $valor > $max) {
            return 'El valor del obsequio no puede superar US$ '.format_rate($max).'.';
        }

        return null;
    }

    private function validarTopeConsumibles(Event $event, Assignment $assignment, float $valor, ?int $ignorarId = null): ?string
    {
        $cap = $event->consumable_value_cap !== null ? (float) $event->consumable_value_cap : null;

        if ($cap === null || $cap <= 0) {
            return null;
        }

        $total = ConsumableGift::query()
            ->where('assignment_id', $assignment->id)
            ->when($ignorarId !== null, fn ($query) => $query->whereKeyNot($ignorarId))
            ->sum('value');

        if ((float) $total + $valor > $cap) {
            $restante = max(0, $cap - (float) $total);

            return 'El tope de consumibles del evento es US$ '.format_rate($cap)
                .'. Te quedan US$ '.format_rate($restante).' disponibles.';
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function giftData(ExchangeGift $gift, Event $event): array
    {
        $tasa = dollar_rate();

        return [
            'nombre' => (string) $gift->product,
            'valor' => (string) $gift->value,
            'valor_usd' => $this->usd($gift->value),
            'valor_bs' => $this->bsDe($gift->value, $tasa),
            'fecha' => format_date($gift->registered_at, 'd/m/Y H:i'),
            'tasa' => $tasa,
            'tasa_formateada' => $tasa !== null ? format_rate($tasa) : null,
            'moneda' => $event->currency,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function consumibleData(ConsumableGift $gift, Event $event): array
    {
        $tasa = dollar_rate();

        return [
            'id' => (int) $gift->id,
            'nombre' => (string) $gift->description,
            'valor' => (string) $gift->value,
            'valor_usd' => $this->usd($gift->value),
            'valor_bs' => $this->bsDe($gift->value, $tasa),
            'fecha' => format_date($gift->given_on, 'd/m/Y'),
            'registrado' => format_date($gift->created_at, 'd/m/Y H:i'),
            'tasa_formateada' => $tasa !== null ? format_rate($tasa) : null,
            'moneda' => $event->currency,
        ];
    }

    private function usd(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return 'US$ '.format_rate($value);
    }

    private function bs(mixed $value): string
    {
        return 'Bs. '.format_rate($value);
    }

    private function bsDe(mixed $usd, ?float $tasa): string
    {
        if ($usd === null || $usd === '' || $tasa === null) {
            return '—';
        }

        return $this->bs((float) $usd * $tasa);
    }

    private function errorValidacion(\Illuminate\Validation\Validator $validator): JsonResponse
    {
        return api_response(false, (string) $validator->errors()->first(), [
            'errors' => $validator->errors(),
        ], 422);
    }

    private function errorValor(string $message): JsonResponse
    {
        return api_response(false, $message, [
            'errors' => ['valor' => [$message]],
        ], 422);
    }
}
