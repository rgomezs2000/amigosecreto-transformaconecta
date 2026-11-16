<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\ChatReport;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SolicitudAdminController extends Controller
{
    private function ensureAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === UserRole::Admin, 403);
    }

    private function close(ChatReport $report, User $admin, string $observation): void
    {
        $report->forceFill([
            'status' => ChatReport::STATUS_CLOSED,
            'closed_at' => now(),
            'closed_by' => $admin->id,
            'assigned_to' => $report->assigned_to ?: $admin->id,
            'taken_by' => $report->taken_by ?: $admin->id,
            'taken_at' => $report->taken_at ?: now(),
            'close_observation' => $observation,
        ])->save();
    }

    private function accountReport(ChatReport $report, string $action): ?User
    {
        if ($report->type !== ChatReport::TYPE_ACCOUNT) {
            return null;
        }

        $payload = $report->payload ?: [];

        if (($payload['action'] ?? null) !== $action) {
            return null;
        }

        return User::query()->find((int) ($payload['user_id'] ?? 0));
    }

    /**
     * Restablece la contraseña y envía las nuevas credenciales al usuario.
     */
    public function restablecer(Request $request, ChatReport $report): JsonResponse
    {
        $this->ensureAdmin($request);
        $user = $this->accountReport($report, 'reset');

        if ($user === null) {
            return api_error('El caso no es una solicitud de restablecimiento válida.', 422);
        }

        if ($report->status === ChatReport::STATUS_CLOSED) {
            return api_error('El caso ya está cerrado.', 409);
        }

        try {
            $password = Str::password(12);
            $user->updatePassword($password);
            $sent = $user->sendPasswordResetEmail($password);

            $this->close(
                $report,
                $request->user(),
                $sent
                    ? 'Contraseña restablecida y enviada al correo del usuario.'
                    : 'Contraseña restablecida, pero el correo no se pudo enviar.'
            );

            return api_success($sent
                ? 'Contraseña restablecida y correo enviado.'
                : 'Contraseña restablecida, pero no se pudo enviar el correo.', [
                'mail_sent' => $sent,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Error al restablecer desde el caso', [
                'report_id' => $report->id,
                'error' => $exception->getMessage(),
            ]);

            return api_error('No se pudo restablecer la contraseña. Intenta de nuevo.', 500);
        }
    }

    /**
     * Desbloquea la cuenta y cierra el caso.
     */
    public function desbloquear(Request $request, ChatReport $report): JsonResponse
    {
        $this->ensureAdmin($request);
        $user = $this->accountReport($report, 'unlock');

        if ($user === null) {
            return api_error('El caso no es una solicitud de desbloqueo válida.', 422);
        }

        if ($report->status === ChatReport::STATUS_CLOSED) {
            return api_error('El caso ya está cerrado.', 409);
        }

        $user->forceFill([
            'is_active' => true,
            'locked_until' => null,
            'failed_login_attempts' => 0,
            'remember_token' => Str::random(60),
        ])->save();

        $this->close($report, $request->user(), 'Cuenta desbloqueada desde el caso.');

        return api_success('Cuenta desbloqueada y caso cerrado.');
    }

    /**
     * Rechaza una solicitud de registro, avisa al solicitante y cierra el caso.
     */
    public function rechazarRegistro(Request $request, ChatReport $report): JsonResponse
    {
        $this->ensureAdmin($request);

        if ($report->type !== ChatReport::TYPE_USER_REGISTRATION) {
            return api_error('El caso no es una solicitud de registro.', 422);
        }

        if ($report->status === ChatReport::STATUS_CLOSED) {
            return api_error('El caso ya está cerrado.', 409);
        }

        $validator = validator($request->all(), [
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'reason.required' => 'Escribe el motivo del rechazo.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), ['errors' => $validator->errors()], 422);
        }

        $payload = $report->payload ?: [];
        $email = (string) ($payload['email'] ?? '');
        $name = (string) ($payload['name'] ?? '');
        $reason = trim((string) $request->input('reason'));
        $sent = false;

        if ($email !== '') {
            try {
                Mail::raw(
                    'Hola '.$name.".\n\n"
                    ."Tu solicitud de registro no fue aprobada por este motivo:\n".$reason."\n\n"
                    ."Puedes volver a intentarlo corrigiendo lo indicado.\n\n"
                    .'Amigo Secreto Inclusivo',
                    function ($message) use ($email) {
                        $message->to($email)->subject('Solicitud de registro no aprobada');
                    }
                );
                $sent = true;
            } catch (\Throwable $exception) {
                Log::warning('No se pudo avisar del rechazo del registro', [
                    'report_id' => $report->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $this->close(
            $report,
            $request->user(),
            'Registro rechazado: '.$reason
        );

        return api_success($sent
            ? 'Solicitud rechazada y aviso enviado por correo.'
            : 'Solicitud rechazada. No se pudo enviar el correo de aviso.', [
            'mail_sent' => $sent,
        ]);
    }
}
