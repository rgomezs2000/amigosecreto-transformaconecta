<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\ChatGroupAudit;
use App\Models\Conversation;
use App\Models\GroupBan;
use App\Models\GroupInvitation;
use App\Models\GroupSuspension;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Invitaciones, suspensiones y baneos de los grupos.
 *
 * El dueño del grupo es quien lo creó. La administración puede hacer
 * lo mismo desde Moderación.
 */
class GroupModerationController extends Controller
{
    public function invitar(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureGroup($conversation);
        $this->ensureOwnerOrAdmin($request->user(), $conversation);

        $validator = validator($request->all(), [
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), ['errors' => $validator->errors()], 422);
        }

        $invitedUserId = $request->input('user_id');

        if ($invitedUserId !== null && GroupBan::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $invitedUserId)
            ->whereNull('revoked_at')
            ->exists()) {
            return api_error('Ese usuario está vetado en este grupo.', 422);
        }

        $invitation = GroupInvitation::query()->create([
            'conversation_id' => $conversation->id,
            'invited_by' => $request->user()->id,
            'invited_user_id' => $invitedUserId,
            'token' => Str::random(48),
        ]);

        ChatGroupAudit::log(
            $conversation,
            $request->user(),
            'invitacion_enviada',
            $invitedUserId !== null ? User::query()->find($invitedUserId) : null,
            'enviada',
            'Invitación para unirse al grupo.'
        );

        return api_success('Invitación creada.', [
            'token' => $invitation->token,
            'url' => route('chat.grupo.invitacion', $invitation->token),
        ]);
    }

    /**
     * La invitación no tiene página propia: se abre dentro del sistema como
     * modal del chat.
     *
     * Se recuerda el token en la sesión y se manda a la persona al panel. Si
     * no tiene sesión, el acceso la loguea y al entrar cae en el panel con el
     * modal de la invitación abierto.
     */
    public function verInvitacion(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->pendingInvitation($token);

        abort_if($invitation === null, 404);

        $request->session()->put('chat.invitation', $token);

        if (! $request->user()) {
            return redirect()->route('login');
        }

        return redirect()->route('dashboard');
    }

    /**
     * Datos de la invitación para el modal (grupo y quién invita).
     */
    public function datosInvitacion(Request $request, string $token): JsonResponse
    {
        $invitation = $this->pendingInvitation($token);

        if ($invitation === null) {
            $request->session()->forget('chat.invitation');

            return api_error('La invitación no existe o ya fue usada.', 404);
        }

        $invitation->load('conversation', 'inviter');

        return api_success('Invitación.', [
            'token' => $invitation->token,
            'group' => $invitation->conversation?->name ?: 'Grupo',
            'inviter' => $invitation->inviter?->name,
        ]);
    }

    public function aceptar(Request $request, string $token): JsonResponse
    {
        $invitation = $this->pendingInvitation($token);

        if ($invitation === null) {
            return api_error('La invitación no existe o ya fue usada.', 404);
        }

        $user = $request->user();

        if (GroupBan::query()
            ->where('conversation_id', $invitation->conversation_id)
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->exists()) {
            return api_error('No puedes unirte: estás vetado en este grupo.', 403);
        }

        $invitation->conversation->users()->syncWithoutDetaching([$user->id]);
        $invitation->update(['accepted_at' => now()]);

        ChatGroupAudit::log(
            $invitation->conversation,
            $user,
            'miembro_agregado',
            $user,
            'activo',
            'Se unió al grupo mediante invitación.'
        );

        // Ya no hay que recordar la invitación al entrar al sistema.
        $request->session()->forget('chat.invitation');

        return api_success('Te uniste al grupo.', ['conversation_id' => $invitation->conversation_id]);
    }

    public function rechazar(Request $request, string $token): JsonResponse
    {
        $invitation = $this->pendingInvitation($token);

        if ($invitation === null) {
            return api_error('La invitación no existe o ya fue usada.', 404);
        }

        $invitation->update(['rejected_at' => now()]);

        ChatGroupAudit::log(
            $invitation->conversation,
            $request->user(),
            'invitacion_rechazada',
            $request->user(),
            'rechazada',
            'La invitación fue rechazada.'
        );

        $request->session()->forget('chat.invitation');

        return api_success('Rechazaste la invitación.');
    }

    public function suspender(Request $request, Conversation $conversation, User $user): JsonResponse
    {
        $this->ensureGroup($conversation);
        $this->ensureOwnerOrAdmin($request->user(), $conversation);

        $validator = validator($request->all(), [
            'days' => ['required', 'integer', 'min:1', 'max:365'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), ['errors' => $validator->errors()], 422);
        }

        $until = now()->addDays((int) $request->input('days'));

        GroupSuspension::query()->updateOrCreate(
            ['conversation_id' => $conversation->id, 'user_id' => $user->id],
            [
                'suspended_by' => $request->user()->id,
                'until' => $until,
                'reason' => $request->input('reason'),
            ]
        );

        ChatGroupAudit::log(
            $conversation,
            $request->user(),
            'suspension',
            $user,
            'suspendido',
            'Suspendido hasta '.$until->format('d/m/Y H:i').'.'
        );

        return api_success('Usuario suspendido hasta '.$until->format('d/m/Y H:i').'.');
    }

    public function banear(Request $request, Conversation $conversation, User $user): JsonResponse
    {
        $this->ensureGroup($conversation);
        $this->ensureOwnerOrAdmin($request->user(), $conversation);

        if ($user->id === $request->user()->id) {
            return api_error('No puedes vetarte a ti mismo.', 422);
        }

        GroupBan::query()->updateOrCreate(
            ['conversation_id' => $conversation->id, 'user_id' => $user->id],
            [
                'banned_by' => $request->user()->id,
                'reason' => $request->input('reason'),
                'revoked_at' => null,
            ]
        );

        $conversation->users()->detach($user->id);

        ChatGroupAudit::log(
            $conversation,
            $request->user(),
            'baneo',
            $user,
            'vetado',
            'Usuario vetado en el grupo.'
        );

        return api_success('Usuario vetado en el grupo.');
    }

    public function desbanear(Request $request, Conversation $conversation, User $user): JsonResponse
    {
        $this->ensureGroup($conversation);
        $this->ensureOwnerOrAdmin($request->user(), $conversation);

        GroupBan::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->update(['revoked_at' => now()]);

        ChatGroupAudit::log(
            $conversation,
            $request->user(),
            'baneo_retirado',
            $user,
            'retirado',
            'Se retiró el veto del usuario.'
        );

        return api_success('Veto retirado.');
    }

    public function baneados(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureGroup($conversation);
        $this->ensureOwnerOrAdmin($request->user(), $conversation);

        $items = GroupBan::query()
            ->where('conversation_id', $conversation->id)
            ->whereNull('revoked_at')
            ->with('user')
            ->get()
            ->map(fn (GroupBan $ban) => [
                'user_id' => $ban->user_id,
                'name' => $ban->user?->name,
                'reason' => $ban->reason,
                'since' => $ban->created_at?->format('d/m/Y H:i'),
            ]);

        return api_success('Vetados del grupo.', ['items' => $items]);
    }

    private function pendingInvitation(string $token): ?GroupInvitation
    {
        return GroupInvitation::query()
            ->where('token', $token)
            ->whereNull('accepted_at')
            ->whereNull('rejected_at')
            ->first();
    }

    private function ensureGroup(Conversation $conversation): void
    {
        abort_unless($conversation->type === 'group', 404);
    }

    private function ensureOwnerOrAdmin(User $user, Conversation $conversation): void
    {
        abort_unless($user->role === UserRole::Admin || $conversation->created_by === $user->id, 403);
    }
}
