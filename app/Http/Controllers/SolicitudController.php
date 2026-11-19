<?php

namespace App\Http\Controllers;

use App\Models\ChatReport;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SolicitudController extends Controller
{
    /** Mismo formato que acepta el alta de usuarios del sistema. */
    private const USERNAME_PATTERN = '/^[A-Za-z0-9._-]{3,30}$/';

    /**
     * Consulta si un nombre de usuario se puede reservar.
     */
    public function usuarioDisponible(Request $request): JsonResponse
    {
        $username = trim((string) $request->query('username', ''));

        if ($username === '') {
            return api_success('Escribe un usuario.', [
                'available' => false,
                'message' => 'Escribe el usuario que quieres usar.',
            ]);
        }

        if (! preg_match(self::USERNAME_PATTERN, $username)) {
            return api_success('Formato no válido.', [
                'available' => false,
                'message' => 'Usa de 3 a 30 letras, números, puntos, guiones o guion bajo.',
            ]);
        }

        return api_success('Consultado.', $this->availability($username));
    }

    /**
     * Solicitud pública de prerregistro. No crea el usuario: crea un caso de
     * tipo «Usuarios» para que la administración lo evalúe.
     */
    public function registro(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'username' => ['required', 'string', 'max:30', 'regex:'.self::USERNAME_PATTERN],
        ], [
            'name.required' => 'Escribe tu nombre.',
            'email.required' => 'Escribe tu correo electrónico.',
            'email.email' => 'Escribe un correo válido.',
            'username.required' => 'Escribe el usuario que quieres usar.',
            'username.regex' => 'Usa de 3 a 30 letras, números, puntos, guiones o guion bajo.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), ['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $username = trim($data['username']);
        $email = trim($data['email']);
        $availability = $this->availability($username);

        if (! $availability['available']) {
            return api_response(false, $availability['message'], [
                'errors' => ['username' => [$availability['message']]],
            ], 422);
        }

        if (User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->exists()) {
            return api_response(false, 'Ese correo ya está registrado.', [
                'errors' => ['email' => ['Ese correo ya está registrado.']],
            ], 422);
        }

        $correoSolicitudAbierta = ChatReport::query()
            ->where('type', ChatReport::TYPE_USER_REGISTRATION)
            ->where('status', '!=', ChatReport::STATUS_CLOSED)
            ->get()
            ->contains(function (ChatReport $report) use ($email) {
                return strcasecmp((string) ($report->payload['email'] ?? ''), $email) === 0;
            });

        if ($correoSolicitudAbierta) {
            return api_response(false, 'Ya existe una solicitud de registro abierta con este correo. Espera la respuesta de la administración.', [
                'errors' => ['email' => ['Ya existe una solicitud de registro abierta con este correo.']],
            ], 422);
        }

        $report = ChatReport::create([
            'type' => ChatReport::TYPE_USER_REGISTRATION,
            'status' => ChatReport::STATUS_PENDING,
            'reason' => 'Solicitud de registro',
            'case_message' => 'La persona solicita registrarse y participar.',
            'payload' => [
                'name' => trim($data['name']),
                'email' => $email,
                'username' => $username,
            ],
        ]);

        return api_success('Solicitud registrada.', [
            'ticket' => (int) $report->id,
            'message' => 'Tu solicitud quedó registrada con el ticket Nº '.$report->id.
                '. La administración la evaluará y, si se aprueba, recibirás tus credenciales por correo.',
        ]);
    }

    /**
     * Solicitud pública de restablecimiento de contraseña: crea un caso de
     * tipo «Usuarios». La respuesta es siempre la misma para no revelar si la
     * cuenta existe.
     */
    public function recuperacion(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'login' => ['required', 'string', 'max:255'],
        ], [
            'login.required' => 'Escribe tu usuario o correo.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), ['errors' => $validator->errors()], 422);
        }

        $login = trim($validator->validated()['login']);
        $user = User::query()->findByLogin($login)->first();

        if ($user !== null) {
            $pending = ChatReport::query()
                ->where('type', ChatReport::TYPE_USER_REGISTRATION)
                ->where('status', '!=', ChatReport::STATUS_CLOSED)
                ->where('payload->action', 'reset')
                ->where('payload->user_id', $user->id)
                ->exists();

            if (! $pending) {
                ChatReport::create([
                    'type' => ChatReport::TYPE_USER_REGISTRATION,
                    'status' => ChatReport::STATUS_PENDING,
                    'reporter_id' => $user->id,
                    'reason' => 'Solicitud de restablecimiento de contraseña',
                    'case_message' => 'La persona olvidó su contraseña y solicita el restablecimiento.',
                    'payload' => [
                        'action' => 'reset',
                        'user_id' => (int) $user->id,
                        'login' => $login,
                    ],
                ]);
            }
        }

        return api_success('Solicitud recibida.', [
            'message' => 'Si la cuenta existe, la administración procesará el restablecimiento y te enviará las credenciales por correo.',
        ]);
    }

    /**
     * @return array{available:bool,message:string}
     */
    private function availability(string $username): array
    {
        if (User::query()->loginExists($username)->exists()) {
            return ['available' => false, 'message' => 'Ese usuario ya está en uso.'];
        }

        $reserved = ChatReport::query()
            ->where('type', ChatReport::TYPE_USER_REGISTRATION)
            ->where('status', '!=', ChatReport::STATUS_CLOSED)
            ->where('payload->username', $username)
            ->exists();

        if ($reserved) {
            return ['available' => false, 'message' => 'Ese usuario está reservado por otra solicitud.'];
        }

        return ['available' => true, 'message' => 'Usuario disponible.'];
    }
}
