<?php

namespace App\Services;

use App\Mail\NotificationMail;
use App\Models\EmailLog;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Services\Firestore\FirestoreService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Envío de correo con rastro doble:
 *  - SQL (email_logs): estado por destinatario, para contar tráfico y fallos.
 *  - Firestore (correos): contenido completo del envío.
 *
 * Si las notificaciones por correo están desactivadas, el envío se omite y
 * queda registrado como "skipped" (también en NoSQL).
 */
class MailNotifier
{
    public function __construct(private readonly FirestoreService $firestore) {}

    /**
     * @param  string|array<int, string>  $to
     * @param  string|array<int, string>  $lines
     * @param  array{label:string, url:string}|null  $action
     * @param  array{recipient_name?:string, eyebrow?:string, preheader?:string, footer_note?:string, source?:string}  $options
     */
    public function send(
        string|array $to,
        string $subject,
        string $title,
        string|array $lines = [],
        ?array $action = null,
        array $options = []
    ): bool {
        $recipients = $this->recipients($to);
        $mailLines = is_array($lines) ? array_values($lines) : [$lines];

        if ($recipients === []) {
            return false;
        }

        $mailOptions = $options;
        $source = (string) ($mailOptions['source'] ?? 'sistema');
        unset($mailOptions['source']);

        $settings = NotificationSetting::current();
        $mirror = [];
        $sent = false;

        foreach ($recipients as $recipient) {
            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($recipient)])
                ->first();

            $status = EmailLog::STATUS_SKIPPED;
            $error = null;

            if ($settings->email_enabled) {
                try {
                    Mail::to($recipient)->send(
                        new NotificationMail($subject, $title, $mailLines, $action, $mailOptions)
                    );

                    $status = EmailLog::STATUS_SENT;
                    $sent = true;
                } catch (Throwable $exception) {
                    $status = EmailLog::STATUS_FAILED;
                    $error = mb_substr($exception->getMessage(), 0, 1000);

                    Log::error('No se pudo enviar el correo', [
                        'to' => $recipient,
                        'subject' => $subject,
                        'error' => $exception->getMessage(),
                    ]);
                }
            } else {
                Log::info('Correo omitido: las notificaciones por correo están desactivadas.', [
                    'to' => $recipient,
                    'subject' => $subject,
                ]);
            }

            $log = EmailLog::create([
                'user_id' => $user?->id,
                'to_email' => $recipient,
                'subject' => $subject,
                'status' => $status,
                'error' => $error,
                'source' => $source,
                'sent_at' => $status === EmailLog::STATUS_SENT ? now() : null,
            ]);

            $mirror[] = [
                'id' => (string) Str::uuid(),
                'email_id' => $log->id,
                'to_email' => $recipient,
                'user_id' => $user?->id,
                'user_name' => $user?->name,
                'subject' => $subject,
                'title' => $title,
                'lines' => array_map(fn (string $line) => $this->maskSensitiveLine($line), $mailLines),
                'action' => $action,
                'status' => $status,
                'error' => $error,
                'source' => $source,
                'sent_at' => $log->sent_at?->toIso8601String(),
                'created_at' => $log->created_at?->toIso8601String(),
            ];
        }

        if ($mirror !== []) {
            $this->firestore->recordEmails($mirror);
        }

        return $sent;
    }

    /**
     * Oculta claves temporales en la copia NoSQL: el correo las lleva, pero
     * no deben quedar almacenadas en Firestore.
     */
    private function maskSensitiveLine(string $line): string
    {
        return (string) preg_replace(
            '/\b(clave|contraseña|password)\s*:\s*.+$/iu',
            '$1: [oculto]',
            $line
        );
    }

    /**
     * @param  string|array<int, string>  $to
     * @return array<int, string>
     */
    private function recipients(string|array $to): array
    {
        $recipients = is_array($to) ? $to : [$to];
        $valid = [];

        foreach ($recipients as $recipient) {
            $recipient = trim((string) $recipient);

            if ($recipient !== '') {
                $valid[] = $recipient;
            }
        }

        return array_values(array_unique($valid));
    }
}
